// ext/pcre/src/pcre.c — PCRE-compatible NFA VM regex engine
// Ported from vlang vlib/regex/pcre/regex.v (MIT, Dario Deledda)
// Adapted for TinyPHP: t_string/t_array types, str_pool_alloc, TCC compat

#include "pcre.h"
// 最小头文件集：仅引入 pcre.c 实际需要的运行时依赖，避免 common.h 拉入
// generator.h/resource.h/file.h 等含非 static 方法定义的头文件（导致链接时重复定义）
#include "val.h"
#include "object/object.h"       // tp_obj_release — runtime.h 需要

// Windows 下 alloca() 声明在 <malloc.h> 中（而非 <stdlib.h>）
#ifdef _WIN32
#include <malloc.h>
#endif

/* 前向声明 runtime.h 中的函数（与 common.h 保持一致）
 * 提前到 exception.h/try.h 之前，避免 GCC 将其隐式声明为 int 导致类型冲突 */
static inline void tphp_rt_str_free(t_string* s);
static inline t_string tphp_rt_str_dup(t_string s);
static inline t_bool tphp_rt_str_eq(t_string a, t_string b);
static inline void tphp_rt_register(void *ptr, int type);
static inline void tphp_rt_unregister(void *ptr);
static inline void tphp_rt_free_all(void);

#include "object/exception.h"    // tphp_class_Exception — try.h 需要
#include "object/try.h"          // tp_throw — array.h 需要
#include "array.h"               // tphp_fn_arr_* — runtime.h 需要
#include "runtime.h"             // str_pool_alloc, tphp_rt_str_*
#include "ext_str.h"
#include "compat/tinycthread.h"  // mtx_t — tp_cache 线程安全锁

#define tp_mk_str(s) ext_mk_str(s)
#define tp_mk_substr(src, start, end) ext_mk_substr(src, start, end)

// ============================================================
// 1. Data Structures
// ============================================================

typedef enum {
    TP_MATCH = 0,    // halt + signal success
    TP_CHAR,         // match single UTF-8 rune
    TP_STRING,        // match sequence of ASCII chars (merged optimization)
    TP_ANY,          // match any char (dot)
    TP_CLASS,        // match character class
    TP_SPLIT,        // branch: target_x (primary), target_y (backtrack)
    TP_JMP,          // unconditional jump
    TP_SAVE,         // save current position to capture group
    TP_ASSERT_START, // ^ start of string
    TP_ASSERT_END,   // $ end of string
    TP_ASSERT_LS,    // ^ start of line (multiline)
    TP_ASSERT_LE,    // $ end of line (multiline)
    TP_ASSERT_BOUND, // \b word boundary
    TP_ASSERT_NBOUND,// \B non-word boundary
    TP_LOOK_START,   // lookahead start: target_x=body, target_y=continuation; inverted=negative
    TP_LOOK_END,     // lookahead body success: target_x=continuation; inverted=negative
    TP_LOOK_FAIL,    // lookahead body failure handler: target_x=continuation; inverted=negative
} tp_inst_type;

typedef struct {
    tp_inst_type typ;
    int          val;          // rune value for TP_CHAR
    char        *str_val;      // string for TP_STRING
    int          str_len;
    int          target_x;     // primary jump/split target
    int          target_y;     // backtrack target for TP_SPLIT
    int          group_idx;    // capture group index for TP_SAVE
    uint32_t     bitmap[4];    // 128-bit bitset for ASCII classes
    bool         inverted;     // negation flag for classes
    bool         ignore_case;
    bool         dot_all;
} tp_inst;

// Backtrack limit to prevent ReDoS (exponential backtracking)
#define TP_BACKTRACK_LIMIT 1000000

// VM execution workspace (zero-allocation: pre-allocated)
typedef struct {
    int  *stack;     // backtracking stack
    int   stack_cap;
    int  *captures;  // [start, end] pairs
    int   cap_size;
    int   backtrack_count;          // incremented per backtrack
    int   backtrack_limit_exceeded; // set when backtrack_count exceeds limit
    // Lookahead stack (max 16 nesting levels): saves sp + stack_ptr checkpoint
    int   look_sp[16];
    int   look_off[16];
    int   look_ptr;
} tp_machine;

// Compiled regex
typedef struct {
    tp_inst *prog;
    int      prog_len;
    int      total_groups;
    char     prefix_lit[256]; // literal prefix for fast-skip
    int      prefix_len;
    bool     has_prefix;
    bool     anchored;
    bool     ignore_case;
    bool     multiline;
    bool     dot_all;
} tp_regex;

// ── Compiler internal structures ──
typedef struct {
    int  min;
    int  max;  // -1 for infinity
    bool greedy;
} tp_quant;

typedef struct {
    bool ignore_case;
    bool multiline;
    bool dot_all;
} tp_flags;

typedef enum {
    TP_NODE_CHAR = 0,
    TP_NODE_ANY,
    TP_NODE_GROUP,
    TP_NODE_ALT,
    TP_NODE_CLASS,
    TP_NODE_SOS,    // start of string ^
    TP_NODE_EOS,    // end of string $
    TP_NODE_WB,     // \b
    TP_NODE_NWB,    // \B
    TP_NODE_WORD,   // \w
    TP_NODE_NWORD,  // \W
    TP_NODE_DIGIT,  // \d
    TP_NODE_NDIGIT, // \D
    TP_NODE_SPACE,  // \s
    TP_NODE_NSPACE, // \S
    TP_NODE_LOWER,  // \a
    TP_NODE_UPPER,  // \A
    TP_NODE_LOOKAHEAD, // (?=...) or (?!...) — inverted flag = negative
} tp_node_type;

typedef struct tp_node {
    tp_node_type typ;
    int          chr;
    tp_quant     quant;
    struct tp_node *nodes;     // children for group
    int          nodes_len;
    struct tp_node *alts;     // alternatives for alternation
    int          alts_len;
    int          group_idx;   // -1 if non-capturing
    int         *char_set;    // for character class
    int          char_set_len;
    bool         inverted;
    bool         ignore_case;
    bool         multiline;
    bool         dot_all;
} tp_node;

// ============================================================
// 2. Utility Functions
// ============================================================

static inline int tp_read_rune(const char *str, int len, int index, int *rune_len) {
    if (index >= len) { *rune_len = 0; return 0; }
    unsigned char b0 = (unsigned char)str[index];
    if (b0 < 0x80) { *rune_len = 1; return b0; }
    if ((b0 & 0xE0) == 0xC0 && index + 1 < len) {
        *rune_len = 2;
        return ((b0 & 0x1F) << 6) | ((unsigned char)str[index+1] & 0x3F);
    }
    if ((b0 & 0xF0) == 0xE0 && index + 2 < len) {
        *rune_len = 3;
        return ((b0 & 0x0F) << 12) | (((unsigned char)str[index+1] & 0x3F) << 6)
               | ((unsigned char)str[index+2] & 0x3F);
    }
    if ((b0 & 0xF8) == 0xF0 && index + 3 < len) {
        *rune_len = 4;
        return ((b0 & 0x07) << 18) | (((unsigned char)str[index+1] & 0x3F) << 12)
               | (((unsigned char)str[index+2] & 0x3F) << 6)
               | ((unsigned char)str[index+3] & 0x3F);
    }
    *rune_len = 1;
    return b0;
}

static inline bool tp_is_word_char(unsigned char c) {
    return (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z')
        || (c >= '0' && c <= '9') || c == '_';
}

static inline void tp_set_bitmap(uint32_t bitmap[4], int r) {
    if (r >= 0 && r < 128) {
        bitmap[(uint32_t)r >> 5] |= (1u << (r & 31));
    }
}

// ============================================================
// 3. Pattern Delimiter Parsing (PHP /pattern/flags format)
// ============================================================

typedef struct {
    const char *pattern;  // pointer into original string
    int         pat_len;
    bool        ignore_case;
    bool        multiline;
    bool        dot_all;
    bool        valid;
} tp_parsed_pattern;

static tp_parsed_pattern tp_parse_delimiters(t_string input) {
    tp_parsed_pattern r = {0};
    const char *s = STR_PTR(input);
    int len = input.length;
    r.valid = false;

    if (!s || len < 2) return r;

    char delim = s[0];
    // PHP allows any non-alphanumeric, non-whitespace, non-backslash delimiter
    if (delim == '\\' || (delim >= '0' && delim <= '9')
        || (delim >= 'a' && delim <= 'z') || (delim >= 'A' && delim <= 'Z'))
        return r;

    // Paired delimiters: () {} [] <>
    char close_delim = delim;
    if (delim == '(') close_delim = ')';
    else if (delim == '{') close_delim = '}';
    else if (delim == '[') close_delim = ']';
    else if (delim == '<') close_delim = '>';

    // Find closing delimiter from the end
    int end = -1;
    for (int i = len - 1; i >= 1; i--) {
        if (s[i] == close_delim) { end = i; break; }
    }
    if (end < 0) return r;

    r.pattern = s + 1;
    r.pat_len = end - 1;
    r.valid = true;

    // Parse flags after closing delimiter
    for (int i = end + 1; i < len; i++) {
        switch (s[i]) {
            case 'i': r.ignore_case = true; break;
            case 'm': r.multiline = true; break;
            case 's': r.dot_all = true; break;
            case 'x': break; // extended (ignored for now)
            case 'u': break; // UTF-8 (always UTF-8 aware)
            default: break;
        }
    }
    return r;
}

// ============================================================
// 4. Compiler — Recursive Descent Parser
// ============================================================

typedef struct {
    const char *pat;
    int         pos;
    int         len;
    tp_flags    flags;
    int         group_counter;
    // group name storage (simple linear array)
    char        group_names[32][32];
    int         group_indices[32];
    int         group_name_count;
} tp_parser;

static tp_node tp_parse_node_impl(tp_parser *p, int terminator, int *out_group_count);

static tp_node *tp_alloc_nodes(int count) {
    return (tp_node *)calloc(count > 0 ? count : 1, sizeof(tp_node));
}

static int *tp_alloc_ints(int count) {
    return (int *)calloc(count > 0 ? count : 1, sizeof(int));
}

static tp_node tp_parse_node_impl(tp_parser *p, int terminator, int *out_group_count) {
    tp_node result = {0};
    result.quant.min = 1; result.quant.max = 1; result.quant.greedy = true;
    result.group_idx = -1;

    // We'll build a sequence of nodes, then wrap in alternation if needed
    tp_node *sequence = tp_alloc_nodes(p->len + 1);
    int seq_len = 0;
    tp_node *alternatives = tp_alloc_nodes(p->len + 1);
    int alt_len = 0;
    bool has_alt = false;

    while (p->pos < p->len) {
        int char_len;
        int chr = tp_read_rune(p->pat, p->len, p->pos, &char_len);

        if (chr == terminator) {
            p->pos += char_len;
            break;
        }

        if (chr == '|') {
            p->pos += char_len;
            has_alt = true;
            // Save current sequence as an alternative
            if (seq_len > 0) {
                // Wrap sequence in a group node
                tp_node grp = {0};
                grp.typ = TP_NODE_GROUP;
                grp.quant.min = 1; grp.quant.max = 1; grp.quant.greedy = true;
                grp.group_idx = -1;
                grp.nodes = sequence; grp.nodes_len = seq_len;
                alternatives[alt_len++] = grp;
                // Allocate new sequence (old one is now owned by grp)
                sequence = tp_alloc_nodes(p->len + 1);
                seq_len = 0;
            } else if (sequence) {
                // seq_len == 0 but sequence was allocated, free it
                free(sequence);
                sequence = tp_alloc_nodes(p->len + 1);
            }
            continue;
        }

        tp_node node = {0};
        node.quant.min = 1; node.quant.max = 1; node.quant.greedy = true;
        node.group_idx = -1;
        node.ignore_case = p->flags.ignore_case;
        node.multiline = p->flags.multiline;
        node.dot_all = p->flags.dot_all;

        if (chr == '^') {
            p->pos += char_len;
            node.typ = TP_NODE_SOS;
            node.multiline = p->flags.multiline;
        } else if (chr == '$') {
            p->pos += char_len;
            node.typ = TP_NODE_EOS;
            node.multiline = p->flags.multiline;
        } else if (chr == '.') {
            p->pos += char_len;
            node.typ = TP_NODE_ANY;
            node.dot_all = p->flags.dot_all;
        } else if (chr == '*' || chr == '+' || chr == '?' || chr == '{') {
            // Quantifier without preceding token — error, but skip
            p->pos += char_len;
            continue;
        } else if (chr == '(') {
            p->pos += char_len;
            int idx = -1;
            bool cap = true;
            bool is_lookahead = false;

            if (p->pos < p->len && p->pat[p->pos] == '?') {
                p->pos++;
                if (p->pos < p->len && (p->pat[p->pos] == '=' || p->pat[p->pos] == '!')) {
                    // Lookahead: (?=...) positive, (?!...) negative
                    bool neg = (p->pat[p->pos] == '!');
                    p->pos++;
                    int sub_groups = 0;
                    tp_node sub = tp_parse_node_impl(p, ')', &sub_groups);
                    p->group_counter = sub_groups;
                    node.typ = TP_NODE_LOOKAHEAD;
                    node.inverted = neg;
                    // Wrap body: if alternation, wrap in group; else use nodes directly
                    if (sub.typ == TP_NODE_ALT) {
                        tp_node *wrap = tp_alloc_nodes(1);
                        wrap[0].typ = TP_NODE_GROUP;
                        wrap[0].quant.min = 1; wrap[0].quant.max = 1; wrap[0].quant.greedy = true;
                        wrap[0].group_idx = -1;
                        wrap[0].alts = sub.alts;
                        wrap[0].alts_len = sub.alts_len;
                        node.nodes = wrap;
                        node.nodes_len = 1;
                    } else {
                        node.nodes = sub.nodes;
                        node.nodes_len = sub.nodes_len;
                    }
                    *out_group_count = p->group_counter;
                    is_lookahead = true;
                } else if (p->pos < p->len && (p->pat[p->pos] == 'i' || p->pat[p->pos] == 'm' || p->pat[p->pos] == 's')) {
                    // Inline flags (?i) (?m) (?s)
                    while (p->pos < p->len && p->pat[p->pos] != ')') {
                        char f = p->pat[p->pos];
                        if (f == 'i') p->flags.ignore_case = true;
                        else if (f == 'm') p->flags.multiline = true;
                        else if (f == 's') p->flags.dot_all = true;
                        p->pos++;
                    }
                    if (p->pos < p->len) p->pos++; // skip ')'
                    continue; // no node added
                } else if (p->pos < p->len && p->pat[p->pos] == ':') {
                    cap = false;
                    p->pos++;
                } else if (p->pos < p->len && p->pat[p->pos] == 'P') {
                    p->pos++;
                    if (p->pos < p->len && p->pat[p->pos] == '<') {
                        p->pos++;
                        int name_start = p->pos;
                        while (p->pos < p->len && p->pat[p->pos] != '>') p->pos++;
                        int name_len = p->pos - name_start;
                        if (name_len > 0 && name_len < 32 && p->pos < p->len) {
                            idx = p->group_counter;
                            if (p->group_name_count < 32) {
                                memcpy(p->group_names[p->group_name_count], p->pat + name_start, name_len);
                                p->group_names[p->group_name_count][name_len] = 0;
                                p->group_indices[p->group_name_count] = idx;
                                p->group_name_count++;
                            }
                        }
                        if (p->pos < p->len) p->pos++; // skip '>'
                    }
                }
            }

            if (!is_lookahead) {
                if (cap) {
                    if (idx == -1) idx = p->group_counter;
                    p->group_counter++;
                }

                int sub_groups = 0;
                tp_node sub = tp_parse_node_impl(p, ')', &sub_groups);
                p->group_counter = sub_groups;
                node.typ = TP_NODE_GROUP;
                node.group_idx = cap ? idx : -1;
                node.nodes = sub.nodes;
                node.nodes_len = sub.nodes_len;
                // If sub was an alternation, preserve it
                if (sub.typ == TP_NODE_ALT) {
                    node.typ = TP_NODE_ALT;
                    node.alts = sub.alts;
                    node.alts_len = sub.alts_len;
                    node.group_idx = cap ? idx : -1;
                }
                *out_group_count = p->group_counter;
            }
        } else if (chr == '[') {
            p->pos += char_len;
            int end = -1;
            for (int i = p->pos; i < p->len; i++) {
                if (p->pat[i] == ']') { end = i; break; }
            }
            if (end < 0) { p->pos = p->len; continue; }

            const char *content = p->pat + p->pos;
            int content_len = end - p->pos;
            p->pos = end + 1;

            bool inv = (content_len > 0 && content[0] == '^');
            int start_i = inv ? 1 : 0;

            int *set = tp_alloc_ints(content_len + 1);
            int set_len = 0;

            int i = start_i;
            while (i < content_len) {
                int c, cl;
                c = tp_read_rune(content, content_len, i, &cl);
                if (i + cl < content_len && content[i + cl] == '-') {
                    int ec, ecl;
                    ec = tp_read_rune(content, content_len, i + cl + 1, &ecl);
                    for (int r = c; r <= ec; r++) set[set_len++] = r;
                    i += cl + 1 + ecl;
                } else {
                    set[set_len++] = c;
                    i += cl;
                }
            }

            node.typ = TP_NODE_CLASS;
            node.char_set = set;
            node.char_set_len = set_len;
            node.inverted = inv;
            node.ignore_case = p->flags.ignore_case;
        } else if (chr == '\\') {
            p->pos += char_len;
            if (p->pos >= p->len) continue;
            int esc, el;
            esc = tp_read_rune(p->pat, p->len, p->pos, &el);
            p->pos += el;

            switch (esc) {
                case 'w': node.typ = TP_NODE_WORD; break;
                case 'W': node.typ = TP_NODE_NWORD; break;
                case 'd': node.typ = TP_NODE_DIGIT; break;
                case 'D': node.typ = TP_NODE_NDIGIT; break;
                case 's': node.typ = TP_NODE_SPACE; break;
                case 'S': node.typ = TP_NODE_NSPACE; break;
                case 'b': node.typ = TP_NODE_WB; break;
                case 'B': node.typ = TP_NODE_NWB; break;
                case 'a': node.typ = TP_NODE_LOWER; break;
                case 'A': node.typ = TP_NODE_UPPER; break;
                case 'n': node.typ = TP_NODE_CHAR; node.chr = '\n'; break;
                case 'r': node.typ = TP_NODE_CHAR; node.chr = '\r'; break;
                case 't': node.typ = TP_NODE_CHAR; node.chr = '\t'; break;
                case 'v': node.typ = TP_NODE_CHAR; node.chr = '\v'; break;
                case 'f': node.typ = TP_NODE_CHAR; node.chr = '\f'; break;
                case '0': node.typ = TP_NODE_CHAR; node.chr = '\0'; break;
                case 'x': {
                    // \xHH
                    if (p->pos + 1 < p->len) {
                        int hi = p->pat[p->pos];
                        int lo = p->pat[p->pos + 1];
                        int hex = 0;
                        if (hi >= '0' && hi <= '9') hex = (hi - '0') << 4;
                        else if (hi >= 'a' && hi <= 'f') hex = (hi - 'a' + 10) << 4;
                        else if (hi >= 'A' && hi <= 'F') hex = (hi - 'A' + 10) << 4;
                        if (lo >= '0' && lo <= '9') hex |= (lo - '0');
                        else if (lo >= 'a' && lo <= 'f') hex |= (lo - 'a' + 10);
                        else if (lo >= 'A' && lo <= 'F') hex |= (lo - 'A' + 10);
                        node.typ = TP_NODE_CHAR; node.chr = hex;
                        p->pos += 2;
                    }
                    break;
                }
                default:
                    node.typ = TP_NODE_CHAR;
                    node.chr = esc;
                    break;
            }
            node.ignore_case = p->flags.ignore_case;
        } else {
            p->pos += char_len;
            node.typ = TP_NODE_CHAR;
            node.chr = chr;
            node.ignore_case = p->flags.ignore_case;
        }

        // Check for quantifier
        if (p->pos < p->len) {
            char peek = p->pat[p->pos];
            if (peek == '*') {
                node.quant.min = 0; node.quant.max = -1; node.quant.greedy = true;
                p->pos++;
            } else if (peek == '+') {
                node.quant.min = 1; node.quant.max = -1; node.quant.greedy = true;
                p->pos++;
            } else if (peek == '?') {
                node.quant.min = 0; node.quant.max = 1; node.quant.greedy = true;
                p->pos++;
            } else if (peek == '{') {
                int end = -1;
                for (int i = p->pos; i < p->len; i++) {
                    if (p->pat[i] == '}') { end = i; break; }
                }
                if (end > 0) {
                    // Parse {min} or {min,max}
                    int s = p->pos + 1;
                    int comma = -1;
                    for (int i = s; i < end; i++) {
                        if (p->pat[i] == ',') { comma = i; break; }
                    }
                    if (comma < 0) {
                        // {n}
                        int n = 0;
                        for (int i = s; i < end; i++) n = n * 10 + (p->pat[i] - '0');
                        node.quant.min = n; node.quant.max = n;
                    } else {
                        // {min,max} or {min,} or {,max}
                        int mn = 0, mx = 0;
                        for (int i = s; i < comma; i++) mn = mn * 10 + (p->pat[i] - '0');
                        if (comma + 1 < end) {
                            for (int i = comma + 1; i < end; i++) mx = mx * 10 + (p->pat[i] - '0');
                        } else {
                            mx = -1; // {min,}
                        }
                        node.quant.min = mn; node.quant.max = mx;
                    }
                    node.quant.greedy = true;
                    p->pos = end + 1;
                }
            }
            // Lazy modifier?
            if (p->pos < p->len && p->pat[p->pos] == '?') {
                node.quant.greedy = false;
                p->pos++;
            }
        }

        sequence[seq_len++] = node;
    }

    // Build result
    if (has_alt) {
        // Save last sequence as alternative
        if (seq_len > 0) {
            tp_node grp = {0};
            grp.typ = TP_NODE_GROUP;
            grp.quant.min = 1; grp.quant.max = 1; grp.quant.greedy = true;
            grp.group_idx = -1;
            grp.nodes = sequence; grp.nodes_len = seq_len;
            alternatives[alt_len++] = grp;
        }
        result.typ = TP_NODE_ALT;
        result.alts = alternatives;
        result.alts_len = alt_len;
        result.quant.min = 1; result.quant.max = 1; result.quant.greedy = true;
    } else {
        result.typ = TP_NODE_GROUP;
        result.group_idx = -1;
        result.nodes = sequence;
        result.nodes_len = seq_len;
    }

    *out_group_count = p->group_counter;
    return result;
}

// ============================================================
// 5. Compiler — Bytecode Emitter
// ============================================================

typedef struct {
    tp_inst *prog;
    int      prog_len;
    int      prog_cap;
} tp_compiler;

static int tp_emit(tp_compiler *c, tp_inst inst) {
    if (c->prog_len >= c->prog_cap) {
        c->prog_cap = c->prog_cap > 0 ? c->prog_cap * 2 : 128;
        tp_inst *new_prog = (tp_inst *)realloc(c->prog, c->prog_cap * sizeof(tp_inst));
        if (!new_prog) return -1;
        c->prog = new_prog;
    }
    c->prog[c->prog_len] = inst;
    return c->prog_len++;
}

static void tp_emit_class(tp_compiler *c, tp_node *node) {
    tp_inst inst = {0};
    inst.typ = TP_CLASS;
    inst.ignore_case = node->ignore_case;

    uint32_t bitmap[4] = {0};
    bool inverted = node->inverted;

    switch (node->typ) {
        case TP_NODE_WORD:
            for (int r = '0'; r <= '9'; r++) tp_set_bitmap(bitmap, r);
            for (int r = 'a'; r <= 'z'; r++) tp_set_bitmap(bitmap, r);
            for (int r = 'A'; r <= 'Z'; r++) tp_set_bitmap(bitmap, r);
            tp_set_bitmap(bitmap, '_');
            break;
        case TP_NODE_NWORD:
            inverted = true;
            for (int r = '0'; r <= '9'; r++) tp_set_bitmap(bitmap, r);
            for (int r = 'a'; r <= 'z'; r++) tp_set_bitmap(bitmap, r);
            for (int r = 'A'; r <= 'Z'; r++) tp_set_bitmap(bitmap, r);
            tp_set_bitmap(bitmap, '_');
            break;
        case TP_NODE_DIGIT:
            for (int r = '0'; r <= '9'; r++) tp_set_bitmap(bitmap, r);
            break;
        case TP_NODE_NDIGIT:
            inverted = true;
            for (int r = '0'; r <= '9'; r++) tp_set_bitmap(bitmap, r);
            break;
        case TP_NODE_SPACE:
            tp_set_bitmap(bitmap, ' ');
            tp_set_bitmap(bitmap, '\t');
            tp_set_bitmap(bitmap, '\n');
            tp_set_bitmap(bitmap, '\r');
            tp_set_bitmap(bitmap, '\v');
            tp_set_bitmap(bitmap, '\f');
            break;
        case TP_NODE_NSPACE:
            inverted = true;
            tp_set_bitmap(bitmap, ' ');
            tp_set_bitmap(bitmap, '\t');
            tp_set_bitmap(bitmap, '\n');
            tp_set_bitmap(bitmap, '\r');
            tp_set_bitmap(bitmap, '\v');
            tp_set_bitmap(bitmap, '\f');
            break;
        case TP_NODE_LOWER:
            for (int r = 'a'; r <= 'z'; r++) tp_set_bitmap(bitmap, r);
            break;
        case TP_NODE_UPPER:
            for (int r = 'A'; r <= 'Z'; r++) tp_set_bitmap(bitmap, r);
            break;
        case TP_NODE_CLASS:
            for (int i = 0; i < node->char_set_len; i++) {
                int r = node->char_set[i];
                tp_set_bitmap(bitmap, r);
                if (node->ignore_case) {
                    if (r >= 'a' && r <= 'z') tp_set_bitmap(bitmap, r - 32);
                    else if (r >= 'A' && r <= 'Z') tp_set_bitmap(bitmap, r + 32);
                }
            }
            break;
        default: break;
    }

    inst.bitmap[0] = bitmap[0]; inst.bitmap[1] = bitmap[1];
    inst.bitmap[2] = bitmap[2]; inst.bitmap[3] = bitmap[3];
    inst.inverted = inverted;
    tp_emit(c, inst);
}

static void tp_emit_logic(tp_compiler *c, tp_node *node);

static void tp_emit_node(tp_compiler *c, tp_node *node) {
    // Emit min repetitions
    for (int i = 0; i < node->quant.min; i++) {
        tp_emit_logic(c, node);
    }

    if (node->quant.max == -1) {
        // Unlimited repetition: split + loop
        int split_idx = tp_emit(c, ((tp_inst){.typ = TP_SPLIT}));
        int start_pc = c->prog_len;
        tp_emit_logic(c, node);
        tp_emit(c, ((tp_inst){.typ = TP_JMP, .target_x = split_idx}));
        if (node->quant.greedy) {
            c->prog[split_idx].target_x = start_pc;
            c->prog[split_idx].target_y = c->prog_len;
        } else {
            c->prog[split_idx].target_x = c->prog_len;
            c->prog[split_idx].target_y = start_pc;
        }
    } else if (node->quant.max > node->quant.min) {
        // Bounded repetition: emit (max-min) optional splits
        int rem = node->quant.max - node->quant.min;
        int *splits = (int *)alloca(rem * sizeof(int));
        for (int i = 0; i < rem; i++) {
            int idx = tp_emit(c, ((tp_inst){.typ = TP_SPLIT}));
            int match_pc = c->prog_len;
            if (node->quant.greedy) {
                c->prog[idx].target_x = match_pc;
            } else {
                c->prog[idx].target_y = match_pc;
            }
            tp_emit_logic(c, node);
            splits[i] = idx;
        }
        int end_pc = c->prog_len;
        for (int i = 0; i < rem; i++) {
            if (node->quant.greedy) {
                c->prog[splits[i]].target_y = end_pc;
            } else {
                c->prog[splits[i]].target_x = end_pc;
            }
        }
    }
}

static void tp_emit_logic(tp_compiler *c, tp_node *node) {
    switch (node->typ) {
        case TP_NODE_CHAR:
            tp_emit(c, ((tp_inst){.typ = TP_CHAR, .val = node->chr,
                                  .ignore_case = node->ignore_case}));
            break;
        case TP_NODE_ANY:
            tp_emit(c, ((tp_inst){.typ = TP_ANY, .dot_all = node->dot_all}));
            break;
        case TP_NODE_GROUP:
            if (node->group_idx >= 0) {
                tp_emit(c, ((tp_inst){.typ = TP_SAVE, .group_idx = node->group_idx * 2}));
            }
            for (int i = 0; i < node->nodes_len; i++) {
                tp_emit_node(c, &node->nodes[i]);
            }
            if (node->group_idx >= 0) {
                tp_emit(c, ((tp_inst){.typ = TP_SAVE, .group_idx = node->group_idx * 2 + 1}));
            }
            break;
        case TP_NODE_ALT: {
            if (node->alts_len == 0) break;
            int *end_jumps = (int *)alloca((node->alts_len - 1) * sizeof(int));
            for (int i = 0; i < node->alts_len - 1; i++) {
                int split_idx = tp_emit(c, ((tp_inst){.typ = TP_SPLIT}));
                c->prog[split_idx].target_x = c->prog_len;
                tp_emit_node(c, &node->alts[i]);
                end_jumps[i] = tp_emit(c, ((tp_inst){.typ = TP_JMP}));
                c->prog[split_idx].target_y = c->prog_len;
            }
            tp_emit_node(c, &node->alts[node->alts_len - 1]);
            for (int i = 0; i < node->alts_len - 1; i++) {
                c->prog[end_jumps[i]].target_x = c->prog_len;
            }
            break;
        }
        case TP_NODE_SOS:
            tp_emit(c, ((tp_inst){.typ = node->multiline ? TP_ASSERT_LS : TP_ASSERT_START}));
            break;
        case TP_NODE_EOS:
            tp_emit(c, ((tp_inst){.typ = node->multiline ? TP_ASSERT_LE : TP_ASSERT_END}));
            break;
        case TP_NODE_WB:
            tp_emit(c, ((tp_inst){.typ = TP_ASSERT_BOUND}));
            break;
        case TP_NODE_NWB:
            tp_emit(c, ((tp_inst){.typ = TP_ASSERT_NBOUND}));
            break;
        case TP_NODE_LOOKAHEAD: {
            // Layout:
            //   TP_LOOK_START  target_x=L_body, target_y=L_fail   ; push frame(pc=L_fail), save sp/offset
            //   TP_LOOK_FAIL   target_x=L_after                   ; body failure handler
            //   L_body: <body expr>
            //   TP_LOOK_END    target_x=L_after                   ; body success handler
            //   L_after: ...
            bool neg = node->inverted;
            int start_idx = tp_emit(c, ((tp_inst){.typ = TP_LOOK_START, .inverted = neg}));
            int fail_idx = tp_emit(c, ((tp_inst){.typ = TP_LOOK_FAIL, .inverted = neg}));
            int body_start = c->prog_len;
            c->prog[start_idx].target_x = body_start;
            c->prog[start_idx].target_y = fail_idx; // fail handler address (for VM + optimizer)
            // Emit body (lookahead is non-capturing, just emit children)
            for (int i = 0; i < node->nodes_len; i++) {
                tp_emit_node(c, &node->nodes[i]);
            }
            int end_idx = tp_emit(c, ((tp_inst){.typ = TP_LOOK_END, .inverted = neg}));
            int after = c->prog_len;
            c->prog[fail_idx].target_x = after;
            c->prog[end_idx].target_x = after;
            break;
        }
        default:
            tp_emit_class(c, node);
            break;
    }
}

// Optimize: merge consecutive TP_CHAR into TP_STRING
static void tp_optimize(tp_compiler *c) {
    // Find jump targets (can't merge through them)
    bool *is_target = (bool *)calloc(c->prog_len + 1, sizeof(bool));
    for (int i = 0; i < c->prog_len; i++) {
        tp_inst_type t = c->prog[i].typ;
        if (t == TP_SPLIT || t == TP_JMP
            || t == TP_LOOK_START || t == TP_LOOK_END || t == TP_LOOK_FAIL) {
            if (c->prog[i].target_x >= 0 && c->prog[i].target_x <= c->prog_len)
                is_target[c->prog[i].target_x] = true;
            if (c->prog[i].target_y >= 0 && c->prog[i].target_y <= c->prog_len)
                is_target[c->prog[i].target_y] = true;
        }
    }

    tp_inst *new_prog = (tp_inst *)calloc(c->prog_len + 1, sizeof(tp_inst));
    int new_len = 0;
    int *idx_map = (int *)calloc(c->prog_len + 1, sizeof(int));

    int i = 0;
    while (i < c->prog_len) {
        tp_inst *inst = &c->prog[i];
        idx_map[i] = new_len;

        // Merge consecutive ASCII chars
        if (inst->typ == TP_CHAR && !inst->ignore_case && inst->val < 128) {
            char buf[256];
            int buf_len = 0;
            buf[buf_len++] = (char)inst->val;
            int j = i + 1;
            while (j < c->prog_len && !is_target[j]
                   && c->prog[j].typ == TP_CHAR
                   && !c->prog[j].ignore_case
                   && c->prog[j].val < 128
                   && buf_len < 255) {
                buf[buf_len++] = (char)c->prog[j].val;
                j++;
            }
            if (buf_len > 1) {
                char *s = (char *)malloc(buf_len + 1);
                memcpy(s, buf, buf_len);
                s[buf_len] = 0;
                tp_inst merged = {0};
                merged.typ = TP_STRING;
                merged.str_val = s;
                merged.str_len = buf_len;
                new_prog[new_len] = merged;
                for (int k = i + 1; k < j; k++) idx_map[k] = new_len;
                new_len++;
                i = j;
                continue;
            }
        }
        new_prog[new_len++] = *inst;
        i++;
    }
    idx_map[c->prog_len] = new_len;

    // Fix jump targets
    for (int k = 0; k < new_len; k++) {
        tp_inst_type t = new_prog[k].typ;
        if (t == TP_SPLIT || t == TP_JMP
            || t == TP_LOOK_START || t == TP_LOOK_END || t == TP_LOOK_FAIL) {
            new_prog[k].target_x = idx_map[new_prog[k].target_x];
            if (t == TP_SPLIT || t == TP_LOOK_START)
                new_prog[k].target_y = idx_map[new_prog[k].target_y];
        }
    }

    free(c->prog);
    free(is_target);
    free(idx_map);
    c->prog = new_prog;
    c->prog_len = new_len;
    c->prog_cap = new_len;
}

// Compile pattern string into tp_regex
static tp_regex *tp_regex_compile(const char *pattern, int pat_len,
                                    bool ignore_case, bool multiline, bool dot_all) {
    tp_parser parser = {0};
    parser.pat = pattern;
    parser.len = pat_len;
    parser.flags.ignore_case = ignore_case;
    parser.flags.multiline = multiline;
    parser.flags.dot_all = dot_all;
    parser.group_counter = 1; // group 0 is the full match start

    int gc = 0;
    tp_node root = tp_parse_node_impl(&parser, -1, &gc);

    tp_compiler comp = {0};
    comp.prog = NULL;
    comp.prog_len = 0;
    comp.prog_cap = 0;

    // Emit SAVE for group 0 (full match start)
    tp_emit(&comp, ((tp_inst){.typ = TP_SAVE, .group_idx = 0}));
    // Root is a group — emit its children
    if (root.typ == TP_NODE_GROUP) {
        for (int i = 0; i < root.nodes_len; i++) {
            tp_emit_node(&comp, &root.nodes[i]);
        }
    } else {
        tp_emit_node(&comp, &root);
    }
    // Emit SAVE for group 0 (full match end)
    tp_emit(&comp, ((tp_inst){.typ = TP_SAVE, .group_idx = 1}));
    tp_emit(&comp, ((tp_inst){.typ = TP_MATCH}));

    tp_optimize(&comp);

    tp_regex *r = (tp_regex *)calloc(1, sizeof(tp_regex));
    if (!r) return NULL;
    r->prog = comp.prog;
    r->prog_len = comp.prog_len;
    r->total_groups = parser.group_counter;
    r->ignore_case = ignore_case;
    r->multiline = multiline;
    r->dot_all = dot_all;

    // Detect prefix and anchor optimizations
    if (r->prog_len > 0) {
        tp_inst *first = &r->prog[0];
        if (first->typ == TP_STRING) {
            int pl = first->str_len;
            if (pl < 256) {
                memcpy(r->prefix_lit, first->str_val, pl);
                r->prefix_lit[pl] = 0;
                r->prefix_len = pl;
                r->has_prefix = true;
            }
        } else if (first->typ == TP_CHAR && !first->ignore_case && first->val < 128) {
            r->prefix_lit[0] = (char)first->val;
            r->prefix_lit[1] = 0;
            r->prefix_len = 1;
            r->has_prefix = true;
        } else if (first->typ == TP_ASSERT_START || first->typ == TP_ASSERT_LS) {
            r->anchored = true;
        }
    }

    return r;
}

// ============================================================
// 6. Virtual Machine Execution
// ============================================================

// Returns match end position, or -1 if no match.
// Fills captures[] array with [start, end] pairs.
static int tp_vm_match(const tp_regex *r, const char *text, int text_len,
                       int start_pos, tp_machine *m) {
    int sp = start_pos;
    int stack_ptr = 0;
    int cap_size = r->total_groups * 2;
    m->look_ptr = 0; // reset lookahead stack

    // Clear captures
    for (int i = 0; i < cap_size; i++) m->captures[i] = -1;

    const tp_inst *prog = r->prog;
    int pc = 0;

    for (;;) {
        if (pc < 0 || pc >= r->prog_len) goto backtrack;

        switch (prog[pc].typ) {
            case TP_MATCH:
                return sp;

            case TP_CHAR: {
                if (sp >= text_len) goto backtrack;
                unsigned char c = (unsigned char)text[sp];
                int inst_val = prog[pc].val;
                // Fast ASCII path
                if (c < 128 && inst_val < 128) {
                    unsigned char c1 = c, c2 = (unsigned char)inst_val;
                    if (prog[pc].ignore_case) {
                        if (c1 >= 'a' && c1 <= 'z') c1 -= 32;
                        if (c2 >= 'a' && c2 <= 'z') c2 -= 32;
                    }
                    if (c1 == c2) { sp++; pc++; continue; }
                    goto backtrack;
                }
                // UTF-8 path
                int rl;
                int rn = tp_read_rune(text, text_len, sp, &rl);
                bool ok = false;
                if (prog[pc].ignore_case) {
                    int r1 = (rn >= 'a' && rn <= 'z') ? rn - 32 : rn;
                    int r2 = (inst_val >= 'a' && inst_val <= 'z') ? inst_val - 32 : inst_val;
                    if (r1 == r2) ok = true;
                } else if (rn == inst_val) {
                    ok = true;
                }
                if (ok) { sp += rl; pc++; } else goto backtrack;
                break;
            }

            case TP_STRING: {
                int sl = prog[pc].str_len;
                if (sp + sl > text_len) goto backtrack;
                const char *sv = prog[pc].str_val;
                for (int i = 0; i < sl; i++) {
                    if ((unsigned char)text[sp + i] != (unsigned char)sv[i])
                        goto backtrack;
                }
                sp += sl;
                pc++;
                break;
            }

            case TP_CLASS: {
                if (sp >= text_len) goto backtrack;
                unsigned char c = (unsigned char)text[sp];
                bool matched = false;
                int cl = 1;
                if (c < 128) {
                    if (prog[pc].bitmap[c >> 5] & (1u << (c & 31)))
                        matched = true;
                } else {
                    int rl;
                    int rn = tp_read_rune(text, text_len, sp, &rl);
                    cl = rl;
                    // Check unicode chars in bitmap (only ASCII range matters)
                    if (rn < 128 && (prog[pc].bitmap[rn >> 5] & (1u << (rn & 31))))
                        matched = true;
                }
                if (matched != prog[pc].inverted) { sp += cl; pc++; }
                else goto backtrack;
                break;
            }

            case TP_ANY: {
                if (sp >= text_len) goto backtrack;
                if (prog[pc].dot_all || text[sp] != '\n') {
                    int rl;
                    tp_read_rune(text, text_len, sp, &rl);
                    sp += rl;
                    pc++;
                } else goto backtrack;
                break;
            }

            case TP_SAVE:
                if (prog[pc].group_idx >= 0 && prog[pc].group_idx < cap_size)
                    m->captures[prog[pc].group_idx] = sp;
                pc++;
                break;

            case TP_SPLIT: {
                int frame_size = cap_size + 2;
                if (stack_ptr + frame_size >= m->stack_cap) {
                    int new_cap = m->stack_cap * 2;
                    if (new_cap > 1000000) goto backtrack;
                    int *ns = (int *)realloc(m->stack, new_cap * sizeof(int));
                    if (!ns) goto backtrack;
                    m->stack = ns;
                    m->stack_cap = new_cap;
                }
                // Push backtrack state
                int off = stack_ptr;
                for (int i = 0; i < cap_size; i++)
                    m->stack[off + i] = m->captures[i];
                m->stack[off + cap_size] = sp;
                m->stack[off + cap_size + 1] = prog[pc].target_y;
                stack_ptr += frame_size;
                pc = prog[pc].target_x;
                break;
            }

            case TP_JMP:
                pc = prog[pc].target_x;
                break;

            case TP_ASSERT_START:
                if (sp == 0) pc++; else goto backtrack;
                break;
            case TP_ASSERT_END:
                if (sp == text_len) pc++; else goto backtrack;
                break;
            case TP_ASSERT_LS:
                if (sp == 0 || (sp > 0 && text[sp - 1] == '\n')) pc++;
                else goto backtrack;
                break;
            case TP_ASSERT_LE:
                if (sp == text_len || text[sp] == '\n') pc++;
                else goto backtrack;
                break;
            case TP_ASSERT_BOUND:
            case TP_ASSERT_NBOUND: {
                bool left = (sp > 0) ? tp_is_word_char((unsigned char)text[sp - 1]) : false;
                bool right = (sp < text_len) ? tp_is_word_char((unsigned char)text[sp]) : false;
                if (prog[pc].typ == TP_ASSERT_BOUND) {
                    if (left != right) pc++; else goto backtrack;
                } else {
                    if (left == right) pc++; else goto backtrack;
                }
                break;
            }

            case TP_LOOK_START: {
                // Save sp and stack_ptr checkpoint, push backtrack frame for body failure
                if (m->look_ptr >= 16) goto backtrack; // nesting overflow
                int frame_size = cap_size + 2;
                if (stack_ptr + frame_size >= m->stack_cap) {
                    int new_cap = m->stack_cap * 2;
                    if (new_cap > 1000000) goto backtrack;
                    int *ns = (int *)realloc(m->stack, new_cap * sizeof(int));
                    if (!ns) goto backtrack;
                    m->stack = ns;
                    m->stack_cap = new_cap;
                }
                m->look_sp[m->look_ptr] = sp;
                m->look_off[m->look_ptr] = stack_ptr;
                m->look_ptr++;
                // Push backtrack frame: (captures, sp, pc=fail_handler)
                // fail_handler = pc + 1 (the TP_LOOK_FAIL instruction right after TP_LOOK_START)
                int off = stack_ptr;
                for (int i = 0; i < cap_size; i++)
                    m->stack[off + i] = m->captures[i];
                m->stack[off + cap_size] = sp;
                m->stack[off + cap_size + 1] = prog[pc].target_y; // fail handler address
                stack_ptr += frame_size;
                pc = prog[pc].target_x; // enter body
                break;
            }

            case TP_LOOK_END: {
                // Body succeeded
                m->look_ptr--;
                int saved_sp = m->look_sp[m->look_ptr];
                int saved_off = m->look_off[m->look_ptr];
                if (prog[pc].inverted) {
                    // Negative: body succeeded → FAIL
                    // Discard lookahead frame + body internals, then backtrack
                    stack_ptr = saved_off;
                    goto backtrack;
                } else {
                    // Positive: body succeeded → SUCCEED (zero-width)
                    // Restore sp, discard lookahead frame + body internals, keep captures
                    sp = saved_sp;
                    stack_ptr = saved_off;
                    pc = prog[pc].target_x; // continuation
                }
                break;
            }

            case TP_LOOK_FAIL: {
                // Body failed — frame was popped by backtrack, captures/sp already restored
                m->look_ptr--;
                if (prog[pc].inverted) {
                    // Negative: body failed → SUCCEED (zero-width, sp already restored)
                    pc = prog[pc].target_x; // continuation
                } else {
                    // Positive: body failed → FAIL
                    goto backtrack;
                }
                break;
            }
        }
        continue;

    backtrack:
        if (stack_ptr <= 0) return -1;
        if (++m->backtrack_count > TP_BACKTRACK_LIMIT) {
            m->backtrack_limit_exceeded = 1;
            return -1;
        }
        int frame_size = cap_size + 2;
        stack_ptr -= frame_size;
        int off = stack_ptr;
        for (int i = 0; i < cap_size; i++)
            m->captures[i] = m->stack[off + i];
        sp = m->stack[off + cap_size];
        pc = m->stack[off + cap_size + 1];
    }
}

// ============================================================
// 7. Public API — find, find_all, replace
// ============================================================

// Find first match starting from start_pos.
// Returns match end position, or -1 if no match.
// Fills captures (must be pre-allocated, size = total_groups * 2)
static int tp_find_from(const tp_regex *r, const char *text, int text_len,
                        int start_pos, tp_machine *m) {
    if (start_pos < 0 || start_pos > text_len) return -1;

    int cap_size = r->total_groups * 2;
    if (cap_size > m->stack_cap) {
        int *ns = (int *)realloc(m->stack, (cap_size + 2) * 64 * sizeof(int));
        if (!ns) return -1;
        m->stack = ns;
        m->stack_cap = (cap_size + 2) * 64;
    }

    // Anchored optimization
    if (r->anchored) {
        if (start_pos == 0)
            return tp_vm_match(r, text, text_len, 0, m);
        if (!r->multiline) return -1;
    }

    // Prefix skip optimization (simple strstr)
    if (r->has_prefix && r->prefix_len > 0) {
        int i = start_pos;
        while (i <= text_len - r->prefix_len) {
            // Find prefix
            const char *found = NULL;
            for (int j = i; j <= text_len - r->prefix_len; j++) {
                bool match = true;
                for (int k = 0; k < r->prefix_len; k++) {
                    if (text[j + k] != r->prefix_lit[k]) { match = false; break; }
                }
                if (match) { found = text + j; i = j; break; }
            }
            if (!found) return -1;
            int res = tp_vm_match(r, text, text_len, i, m);
            if (res >= 0) return res;
            if (m->backtrack_limit_exceeded) return -1;
            i++;
        }
        return -1;
    }

    // Standard scan
    for (int i = start_pos; i <= text_len; i++) {
        // Skip UTF-8 continuation bytes
        if (i > 0 && i < text_len && ((unsigned char)text[i] & 0xC0) == 0x80)
            continue;
        int res = tp_vm_match(r, text, text_len, i, m);
        if (res >= 0) return res;
        if (m->backtrack_limit_exceeded) return -1;
    }
    return -1;
}

// ============================================================
// 8. Compilation Cache (simple LRU, 32 entries)
// ============================================================

#define TP_CACHE_SIZE 32

typedef struct {
    char      *key;        // pattern string (with delimiters), malloc 分配支持任意长度
    int       key_len;
    tp_regex *regex;
    bool      used;
} tp_cache_entry;

static tp_cache_entry tp_cache[TP_CACHE_SIZE];
static int tp_cache_clock = 0;

// 线程安全：mtx 保护 lookup + compile + insert，防止数据结构损坏和重复编译
static mtx_t tp_cache_lock;
static int tp_cache_lock_inited = 0;

static tp_regex *tp_get_or_compile(t_string pattern) {
    // 懒初始化锁（首次调用通常在主线程单线程阶段，竞态可接受）
    if (!tp_cache_lock_inited) {
        mtx_init(&tp_cache_lock, mtx_plain);
        tp_cache_lock_inited = 1;
    }

    const char *key = STR_PTR(pattern);
    int key_len = pattern.length;

    mtx_lock(&tp_cache_lock);

    // Search cache
    for (int i = 0; i < TP_CACHE_SIZE; i++) {
        if (tp_cache[i].used && tp_cache[i].key != NULL
            && tp_cache[i].key_len == key_len
            && memcmp(tp_cache[i].key, key, key_len) == 0) {
            tp_regex *r = tp_cache[i].regex;
            mtx_unlock(&tp_cache_lock);
            return r;
        }
    }

    // Parse delimiters
    tp_parsed_pattern pp = tp_parse_delimiters(pattern);
    if (!pp.valid) { mtx_unlock(&tp_cache_lock); return NULL; }

    // Copy pattern to ensure it's valid during compilation
    char *pat_copy = (char *)malloc(pp.pat_len + 1);
    if (!pat_copy) { mtx_unlock(&tp_cache_lock); return NULL; }
    memcpy(pat_copy, pp.pattern, pp.pat_len);
    pat_copy[pp.pat_len] = '\0';

    tp_regex *r = tp_regex_compile(pat_copy, pp.pat_len,
                                    pp.ignore_case, pp.multiline, pp.dot_all);
    free(pat_copy);
    if (!r) { mtx_unlock(&tp_cache_lock); return NULL; }

    // Cache it
    int slot = tp_cache_clock;
    tp_cache_clock = (tp_cache_clock + 1) % TP_CACHE_SIZE;

    if (tp_cache[slot].used && tp_cache[slot].regex) {
        // Free old entry
        for (int i = 0; i < tp_cache[slot].regex->prog_len; i++) {
            if (tp_cache[slot].regex->prog[i].typ == TP_STRING
                && tp_cache[slot].regex->prog[i].str_val) {
                free(tp_cache[slot].regex->prog[i].str_val);
            }
        }
        free(tp_cache[slot].regex->prog);
        free(tp_cache[slot].regex);
        free(tp_cache[slot].key);
    }

    tp_cache[slot].used = true;
    tp_cache[slot].key_len = key_len;
    tp_cache[slot].key = (char *)malloc((size_t)key_len + 1);
    if (tp_cache[slot].key) {
        memcpy(tp_cache[slot].key, key, key_len);
        tp_cache[slot].key[key_len] = 0;
    }
    tp_cache[slot].regex = r;

    mtx_unlock(&tp_cache_lock);
    return r;
}

// ============================================================
// 9. Helper: create t_string from substring
// (moved to ext/common/ext_str.h as ext_mk_substr / ext_mk_str)
// ============================================================

// ============================================================
// 10. PHP API Functions — tphp_fn_ 前缀，无 byRef，直接返回结果
// ============================================================

// 全局错误状态
static t_int g_pcre_last_error = 0;

// preg_match: 返回匹配数组；空数组=无匹配，NULL=编译错误
t_array* tphp_fn_preg_match(t_string pattern, t_string subject) {
    g_pcre_last_error = PREG_NO_ERROR;
    tp_regex *r = tp_get_or_compile(pattern);
    if (!r) { g_pcre_last_error = PREG_INTERNAL_ERROR; return tphp_fn_arr_create(0); }

    const char *text = STR_PTR(subject);
    int text_len = subject.length;
    if (!text || text_len < 0) text_len = 0;

    tp_machine m = {0};
    m.stack_cap = 4096;
    m.stack = (int *)malloc(m.stack_cap * sizeof(int));
    if (!m.stack) return tphp_fn_arr_create(0);
    m.cap_size = r->total_groups * 2;
    m.captures = (int *)calloc(m.cap_size > 0 ? m.cap_size : 1, sizeof(int));
    if (!m.captures) { free(m.stack); return tphp_fn_arr_create(0); }

    int end = tp_find_from(r, text, text_len, 0, &m);
    if (m.backtrack_limit_exceeded) {
        g_pcre_last_error = PREG_BACKTRACK_LIMIT_ERROR;
        free(m.stack); free(m.captures);
        return tphp_fn_arr_create(0);
    }

    t_array* result = tphp_fn_arr_create(r->total_groups);
    if (end >= 0) {
        int start = m.captures[0];
        if (start < 0) start = 0;
        t_string full = tp_mk_substr(text, start, end);
        result = tphp_fn_arr_push(result, VAR_STRING(full));
        for (int g = 1; g < r->total_groups; g++) {
            int gs = m.captures[g * 2];
            int ge = m.captures[g * 2 + 1];
            if (gs >= 0 && ge >= gs) {
                t_string grp = tp_mk_substr(text, gs, ge);
                result = tphp_fn_arr_push(result, VAR_STRING(grp));
            } else {
                result = tphp_fn_arr_push(result, VAR_STRING((t_string){0}));
            }
        }
    }

    free(m.stack);
    free(m.captures);
    return result;
}

// preg_match_all: 返回 PREG_PATTERN_ORDER 风格二维数组
t_array* tphp_fn_preg_match_all(t_string pattern, t_string subject) {
    g_pcre_last_error = PREG_NO_ERROR;
    tp_regex *r = tp_get_or_compile(pattern);
    if (!r) { g_pcre_last_error = PREG_INTERNAL_ERROR; return tphp_fn_arr_create(0); }

    const char *text = STR_PTR(subject);
    int text_len = subject.length;
    if (!text || text_len < 0) text_len = 0;

    tp_machine m = {0};
    m.stack_cap = 4096;
    m.stack = (int *)malloc(m.stack_cap * sizeof(int));
    if (!m.stack) return tphp_fn_arr_create(0);
    m.cap_size = r->total_groups * 2;
    m.captures = (int *)calloc(m.cap_size > 0 ? m.cap_size : 1, sizeof(int));
    if (!m.captures) { free(m.stack); return tphp_fn_arr_create(0); }

    t_array* result = tphp_fn_arr_create(r->total_groups);
    for (int g = 0; g < r->total_groups; g++) {
        result = tphp_fn_arr_push(result, VAR_ARRAY(tphp_fn_arr_create(4)));
    }

    int pos = 0;
    while (pos <= text_len) {
        int end = tp_find_from(r, text, text_len, pos, &m);
        if (end < 0) break;
        if (m.backtrack_limit_exceeded) { g_pcre_last_error = PREG_BACKTRACK_LIMIT_ERROR; break; }

        int start = m.captures[0];
        if (start < 0) start = pos;

        for (int g = 0; g < r->total_groups; g++) {
            int gs = m.captures[g * 2];
            int ge = m.captures[g * 2 + 1];
            t_string grp;
            if (g == 0) {
                grp = tp_mk_substr(text, start, end);
            } else if (gs >= 0 && ge >= gs) {
                grp = tp_mk_substr(text, gs, ge);
            } else {
                grp = (t_string){0};
            }
            t_var *sub = &result->entries[g].val;
            if (sub->type == TYPE_ARRAY && sub->value._array != NULL) {
                sub->value._array = tphp_fn_arr_push(sub->value._array, VAR_STRING(grp));
            }
        }

        if (end > pos) pos = end;
        else pos++;
    }

    free(m.stack);
    free(m.captures);
    return result;
}

// preg_replace: $limit=-1 无限制；支持 $1/$2 反向引用
// P3-6 优化：首次匹配时保存所有 captures，替换时直接查表（O(n+matches)），
//           不再为每个 $N 反向引用重跑 tp_find_from（原 O(n×matches×backrefs)）
t_string tphp_fn_preg_replace(t_string pattern, t_string replacement,
                              t_string subject, t_int limit) {
    g_pcre_last_error = PREG_NO_ERROR;
    tp_regex *r = tp_get_or_compile(pattern);
    if (!r) { g_pcre_last_error = PREG_INTERNAL_ERROR; return subject; }

    const char *text = STR_PTR(subject);
    int text_len = subject.length;
    if (!text || text_len < 0) text_len = 0;

    const char *repl = STR_PTR(replacement);
    int repl_len = replacement.length;
    if (!repl || repl_len < 0) repl_len = 0;

    tp_machine m = {0};
    m.stack_cap = 4096;
    m.stack = (int *)malloc(m.stack_cap * sizeof(int));
    if (!m.stack) return subject;
    m.cap_size = r->total_groups * 2;
    m.captures = (int *)calloc(m.cap_size > 0 ? m.cap_size : 1, sizeof(int));
    if (!m.captures) { free(m.stack); return subject; }

    int pos = 0;
    int *match_starts = NULL;
    int *match_ends = NULL;
    int *match_caps = NULL;       // P3-6: 每个匹配的完整 captures（match_count × cap_size）
    int match_count = 0;
    int match_cap = 0;
    int max_matches = (limit > 0) ? (int)limit : 0x7FFFFFFF;
    int cap_sz = m.cap_size;      // 保存 cap_size 供后续使用（m 释放后仍需）

    while (pos <= text_len && match_count < max_matches) {
        int end = tp_find_from(r, text, text_len, pos, &m);
        if (end < 0) break;
        if (m.backtrack_limit_exceeded) { g_pcre_last_error = PREG_BACKTRACK_LIMIT_ERROR; break; }
        int start = m.captures[0];
        if (start < 0) start = pos;
        if (match_count >= match_cap) {
            match_cap = match_cap > 0 ? match_cap * 2 : 16;
            match_starts = (int *)realloc(match_starts, match_cap * sizeof(int));
            match_ends = (int *)realloc(match_ends, match_cap * sizeof(int));
            match_caps = (int *)realloc(match_caps, (size_t)match_cap * (cap_sz > 0 ? cap_sz : 1) * sizeof(int));
        }
        match_starts[match_count] = start;
        match_ends[match_count] = end;
        if (cap_sz > 0) {
            memcpy(match_caps + (size_t)match_count * cap_sz, m.captures, cap_sz * sizeof(int));
        }
        match_count++;
        if (end > pos) pos = end; else pos++;
    }

    free(m.stack); free(m.captures);

    if (match_count == 0) { free(match_starts); free(match_ends); free(match_caps); return subject; }

    // 计算 result_len（必须考虑 $N 反向引用展开为捕获组内容，否则缓冲区分配不足）
    int result_len = text_len;
    for (int i = 0; i < match_count; i++) {
        int match_len = match_ends[i] - match_starts[i];
        int repl_expanded_len = 0;
        int *caps_i = (cap_sz > 0) ? (match_caps + (size_t)i * cap_sz) : NULL;
        for (int j = 0; j < repl_len; j++) {
            if (repl[j] == '$' && j + 1 < repl_len && repl[j+1] >= '1' && repl[j+1] <= '9') {
                int g = repl[j+1] - '0';
                if (g < r->total_groups && caps_i != NULL) {
                    int gs = caps_i[g * 2];
                    int ge = caps_i[g * 2 + 1];
                    if (gs >= 0 && ge >= gs) {
                        repl_expanded_len += ge - gs;
                    }
                }
                j++; // 跳过数字
            } else {
                repl_expanded_len++;
            }
        }
        result_len += repl_expanded_len - match_len;
    }
    if (result_len < 0) result_len = text_len;

    // Use malloc for result buffer (str_pool_alloc causes issues with tphp_rt_str_free)
    char *result_buf = (char *)malloc(result_len + 1);
    if (!result_buf) { free(match_starts); free(match_ends); free(match_caps); return subject; }

    // Initialize buffer to avoid garbage
    memset(result_buf, 0, result_len + 1);

    int wpos = 0, src_pos = 0;
    for (int mi = 0; mi < match_count; mi++) {
        int start = match_starts[mi], end = match_ends[mi];
        if (start > src_pos) { memcpy(result_buf + wpos, text + src_pos, start - src_pos); wpos += start - src_pos; }
        // P3-6: 直接查表获取 captures，无需重跑 tp_find_from
        int *caps = (cap_sz > 0) ? (match_caps + (size_t)mi * cap_sz) : NULL;
        // Copy replacement with $N backreference support
        for (int i = 0; i < repl_len; i++) {
            if (repl[i] == '$' && i + 1 < repl_len && repl[i+1] >= '1' && repl[i+1] <= '9') {
                int g = repl[i+1] - '0';
                if (g < r->total_groups && caps != NULL) {
                    int gs = caps[g * 2];
                    int ge = caps[g * 2 + 1];
                    if (gs >= 0 && ge >= gs) {
                        memcpy(result_buf + wpos, text + gs, ge - gs);
                        wpos += ge - gs;
                    }
                }
                i++;
            } else {
                result_buf[wpos++] = repl[i];
            }
        }
        src_pos = end;
    }
    if (src_pos < text_len) { memcpy(result_buf + wpos, text + src_pos, text_len - src_pos); wpos += text_len - src_pos; }
    result_buf[wpos] = '\0';

    free(match_starts); free(match_ends); free(match_caps);
    return (t_string){.data = result_buf, .length = wpos, .is_local = false};
}

// preg_quote: 转义正则特殊字符
t_string tphp_fn_preg_quote(t_string str, t_string delimiter) {
    const char *s = STR_PTR(str);
    int len = str.length;
    if (!s || len <= 0) return (t_string){0};

    const char *delim = STR_PTR(delimiter);
    int delim_len = delimiter.length;

    int quoted_len = 0;
    for (int i = 0; i < len; i++) {
        char c = s[i];
        if (c == '.' || c == '\\' || c == '+' || c == '*' || c == '?'
            || c == '[' || c == '^' || c == ']' || c == '$' || c == '('
            || c == ')' || c == '{' || c == '}' || c == '=' || c == '!'
            || c == '<' || c == '>' || c == '|' || c == ':' || c == '-'
            || c == '#') {
            quoted_len += 2;
        } else {
            bool is_delim = false;
            for (int d = 0; d < delim_len; d++) {
                if (c == delim[d]) { is_delim = true; break; }
            }
            quoted_len += is_delim ? 2 : 1;
        }
    }

    char *buf = (char *)malloc(quoted_len + 1);
    if (!buf) return (t_string){0};
    int wpos = 0;
    for (int i = 0; i < len; i++) {
        char c = s[i];
        bool need_quote = (c == '.' || c == '\\' || c == '+' || c == '*'
            || c == '?' || c == '[' || c == '^' || c == ']' || c == '$'
            || c == '(' || c == ')' || c == '{' || c == '}' || c == '='
            || c == '!' || c == '<' || c == '>' || c == '|' || c == ':'
            || c == '-' || c == '#');
        if (!need_quote) {
            for (int d = 0; d < delim_len; d++) {
                if (c == delim[d]) { need_quote = true; break; }
            }
        }
        if (need_quote) buf[wpos++] = '\\';
        buf[wpos++] = c;
    }
    buf[wpos] = 0;
    return (t_string){.data = buf, .length = wpos, .is_local = false};
}

// preg_split: $limit=-1 无限制
t_array* tphp_fn_preg_split(t_string pattern, t_string subject, t_int limit, t_int flags) {
    g_pcre_last_error = PREG_NO_ERROR;
    tp_regex *r = tp_get_or_compile(pattern);
    if (!r) { g_pcre_last_error = PREG_INTERNAL_ERROR; return tphp_fn_arr_create(0); }

    const char *text = STR_PTR(subject);
    int text_len = subject.length;
    if (!text || text_len < 0) text_len = 0;

    bool no_empty = (flags & PREG_SPLIT_NO_EMPTY) != 0;

    tp_machine m = {0};
    m.stack_cap = 4096;
    m.stack = (int *)malloc(m.stack_cap * sizeof(int));
    if (!m.stack) return tphp_fn_arr_create(0);
    m.cap_size = r->total_groups * 2;
    m.captures = (int *)calloc(m.cap_size > 0 ? m.cap_size : 1, sizeof(int));
    if (!m.captures) { free(m.stack); return tphp_fn_arr_create(0); }

    t_array* result = tphp_fn_arr_create(8);
    int pos = 0;
    int count = 0;

    while (pos <= text_len) {
        if (limit > 0 && count >= limit - 1) break;
        int end = tp_find_from(r, text, text_len, pos, &m);
        if (end < 0) break;
        if (m.backtrack_limit_exceeded) { g_pcre_last_error = PREG_BACKTRACK_LIMIT_ERROR; break; }
        int start = m.captures[0];
        if (start < 0) start = pos;
        int piece_len = start - pos;
        if (piece_len > 0 || !no_empty) {
            t_string piece = (piece_len > 0) ? tp_mk_substr(text, pos, start) : (t_string){0};
            result = tphp_fn_arr_push(result, VAR_STRING(piece));
            count++;
        }
        if (end > pos) pos = end; else pos++;
    }

    if (pos < text_len) {
        int piece_len = text_len - pos;
        if (piece_len > 0 || !no_empty) {
            t_string piece = tp_mk_substr(text, pos, text_len);
            result = tphp_fn_arr_push(result, VAR_STRING(piece));
        }
    } else if (pos == text_len && (limit <= 0 || count < limit) && !no_empty) {
        result = tphp_fn_arr_push(result, VAR_STRING((t_string){0}));
    }

    free(m.stack);
    free(m.captures);
    return result;
}

// preg_grep: $flags=PREG_GREP_INVERT 返回不匹配的元素
t_array* tphp_fn_preg_grep(t_string pattern, t_array* input, t_int flags) {
    g_pcre_last_error = PREG_NO_ERROR;
    tp_regex *r = tp_get_or_compile(pattern);
    if (!r || !input) { g_pcre_last_error = PREG_INTERNAL_ERROR; return tphp_fn_arr_create(0); }

    bool invert = (flags & PREG_GREP_INVERT) != 0;

    tp_machine m = {0};
    m.stack_cap = 4096;
    m.stack = (int *)malloc(m.stack_cap * sizeof(int));
    if (!m.stack) return tphp_fn_arr_create(0);
    m.cap_size = r->total_groups * 2;
    m.captures = (int *)calloc(m.cap_size > 0 ? m.cap_size : 1, sizeof(int));
    if (!m.captures) { free(m.stack); return tphp_fn_arr_create(0); }

    t_array* result = tphp_fn_arr_create(input->length);

    for (int i = 0; i < input->length; i++) {
        t_var *entry = &input->entries[i].val;
        if (entry->type != TYPE_STRING) continue;

        const char *text = STR_PTR(entry->value._string);
        int text_len = entry->value._string.length;
        if (!text || text_len < 0) text_len = 0;

        int end = tp_find_from(r, text, text_len, 0, &m);
        if (m.backtrack_limit_exceeded) { g_pcre_last_error = PREG_BACKTRACK_LIMIT_ERROR; break; }
        bool matched = (end >= 0);

        if (matched != invert) {
            if (input->entries[i].key.type == TYPE_INT) {
                result = tphp_fn_arr_set_int(result, input->entries[i].key.value._int, *entry);
            } else {
                result = tphp_fn_arr_push(result, *entry);
            }
        }
    }

    free(m.stack);
    free(m.captures);
    return result;
}

// preg_last_error: 返回最后错误码
t_int tphp_fn_preg_last_error(void) {
    return g_pcre_last_error;
}

// preg_last_error_msg: 返回最后错误消息
t_string tphp_fn_preg_last_error_msg(void) {
    switch (g_pcre_last_error) {
        case PREG_NO_ERROR:               return tp_mk_str("No error");
        case PREG_INTERNAL_ERROR:         return tp_mk_str("Internal error: regex compilation failed");
        case PREG_BACKTRACK_LIMIT_ERROR:  return tp_mk_str("Backtrack limit exhausted");
        case PREG_RECURSION_LIMIT_ERROR:  return tp_mk_str("Recursion limit exhausted");
        default: return tp_mk_str("Unknown error");
    }
}
