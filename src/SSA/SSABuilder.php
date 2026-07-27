<?php

declare(strict_types=1);

// ============================================================
// SSABuilder — 从 FlatAst 构建 SSA
//
// 输入：FlatAst + FunctionNode 索引
// 输出：SSAFunction（含 CFG、SSA 指令、phi 节点）
//
// 构建流程：
//   1. 读取 FunctionNode 元数据（name / returnType / paramCount）
//   2. 创建 entry block，为每个参数创建 SSA value
//   3. 遍历函数体语句，生成 SSA 指令（buildStmt）
//   4. 遇到 if/while 时创建新 block 和跳转指令，维护 CFG 边
//   5. 维护 varName → SSA value id 映射（每次赋值创建新 value）
//   6. insertPhiNodes：在合并点（多前驱块）为变量插入 PHI 指令
//
// 简化说明：
//   - 不计算支配前沿，改为在所有合并点插入 phi
//   - phi 插入后做局部 use 重写（仅在 merge block 内重写旧入口值 → phi 结果）
//   - 循环体回边场景下，body 内指令不重写（已知限制，需完整 SSA renamer）
//   - 支持语句：return / assign / echo / expr stmt / if / while / 表达式
// ============================================================

require_once __DIR__ . '/../AST/FlatAst.php';
require_once __DIR__ . '/SSA.php';

class SSABuilder
{
    private FlatAst $ast;
    private SSAFunction $func;

    /** 当前正在构建的 block id */
    private int $currentBlockId = -1;

    /** varName(含$) => 当前 SSA value id */
    private array $currentVersion = [];

    /** blockId => 该 block 入口处的 currentVersion 快照 */
    private array $blockEntryVersions = [];

    /** blockId => 该 block 出口处的 currentVersion 快照（终结指令发出后） */
    private array $blockExitVersions = [];

    /** 所有被赋值过的变量名集合（用于 phi 插入） */
    private array $assignedVars = [];

    // ─────────────────────────────────────────────────────────────
    // 公开入口
    // ─────────────────────────────────────────────────────────────

    /**
     * 从 FlatAst 构建一个函数的 SSA。
     *
     * @param FlatAst $ast         FlatAst 容器
     * @param int     $funcNodeIdx FunctionNode 在 FlatAst 中的索引
     * @param bool    $runPhi      是否在构建后插入 phi 节点（默认 true）
     * @return SSAFunction
     */
    public function build(FlatAst $ast, int $funcNodeIdx, bool $runPhi = true): SSAFunction
    {
        $this->ast = $ast;
        $this->resetState();

        $fnode = $ast->nodes[$funcNodeIdx];
        $fname = (string)$fnode['value'];
        $retTypeStr = (string)($fnode['extra']['returnType'] ?? 'void');
        $retType = $this->typeFromString($retTypeStr);
        $paramCount = (int)($fnode['extra']['paramCount'] ?? 0);
        $attrCount = (int)($fnode['extra']['attributeCount'] ?? 0);

        $paramTypes = [];
        $paramStart = $attrCount;
        for ($i = 0; $i < $paramCount; $i++) {
            $paramIdx = $ast->child($funcNodeIdx, $paramStart + $i);
            $pnode = $ast->nodes[$paramIdx];
            $ptypeStr = (string)($pnode['extra']['type'] ?? 'int');
            $paramTypes[] = $this->typeFromString($ptypeStr);
        }

        $this->func = new SSAFunction($fname, $retType, $paramTypes);

        // 创建 entry block
        $entryId = $this->func->newBlock('entry');
        $this->func->entryBlockId = $entryId;
        $this->currentBlockId = $entryId;

        // 为每个参数创建 SSA value
        for ($i = 0; $i < $paramCount; $i++) {
            $paramIdx = $ast->child($funcNodeIdx, $paramStart + $i);
            $pnode = $ast->nodes[$paramIdx];
            $pname = (string)$pnode['value']; // 含 $
            $ptype = $paramTypes[$i];
            $vid = $this->func->newValue($ptype, $this->varDisplayName($pname));
            $this->currentVersion[$pname] = $vid;
        }

        // 记录 entry block 入口版本（参数赋值后）
        $this->blockEntryVersions[$entryId] = $this->currentVersion;

        // 遍历函数体语句（children 中 param 之后的部分）
        $bodyStart = $paramStart + $paramCount;
        $childCount = $ast->childCount($funcNodeIdx);
        for ($i = $bodyStart; $i < $childCount; $i++) {
            $stmtIdx = $ast->child($funcNodeIdx, $i);
            $this->buildStmt($stmtIdx);
        }

        // 若当前块未以终结指令收尾，补一个 RET
        $this->ensureTerminator();

        // 记录最后一个块的出口版本
        $this->blockExitVersions[$this->currentBlockId] = $this->currentVersion;

        // 插入 phi 节点
        if ($runPhi) {
            $this->insertPhiNodes($this->func);
        }

        return $this->func;
    }

    /**
     * 在已构建的 SSAFunction 中插入 phi 节点（简化版）。
     *
     * 算法：
     *   1. 遍历所有合并点（predecessors >= 2 的块）
     *   2. 对每个被赋值过的变量，收集各前驱块出口处的 value id
     *   3. 若 incoming 值不全相同，在块开头插入 PHI 指令
     *   4. 局部 use 重写：在该块内将旧入口值替换为 phi 结果
     *
     * @param SSAFunction $func
     */
    public function insertPhiNodes(SSAFunction $func): void
    {
        // 若无构建快照（未先调用 build），直接返回
        if (empty($this->blockEntryVersions) || empty($this->blockExitVersions)) {
            return;
        }

        // 按 block id 升序处理（保证前驱通常先于后继处理）
        $blockIds = array_keys($func->blocks);
        sort($blockIds);

        foreach ($blockIds as $bid) {
            $block = $func->blocks[$bid];
            if (!$block->isMergePoint()) {
                continue;
            }
            $preds = $block->predecessors;
            $entryVersion = $this->blockEntryVersions[$bid] ?? [];

            // 为每个被赋值变量尝试插入 phi
            // 注意：assignedVars 是 [varName => true] 形式，需遍历键而非值
            foreach (array_keys($this->assignedVars) as $varName) {
                if (!array_key_exists($varName, $entryVersion)) {
                    continue; // 该变量在此块入口不在作用域内
                }

                $incoming = [];
                foreach ($preds as $pid) {
                    $exitVer = $this->blockExitVersions[$pid] ?? null;
                    if ($exitVer !== null && array_key_exists($varName, $exitVer)) {
                        $incoming[] = $exitVer[$varName];
                    } else {
                        // 前驱无该变量版本：回退到当前块入口版本
                        $incoming[] = $entryVersion[$varName];
                    }
                }

                // 若所有 incoming 相同，无需 phi
                if (count(array_unique($incoming)) <= 1) {
                    continue;
                }

                // 创建 phi 结果 value
                $oldVal = $entryVersion[$varName];
                $oldType = $func->values[$oldVal]->type ?? SSAType::int();
                $phiVal = $func->newValue($oldType, $this->varDisplayName($varName));

                // 在块开头插入 PHI
                $phi = new SSAInstruction(
                    OpCode::PHI,
                    $phiVal,
                    $incoming,
                    ['blocks' => $preds],
                );
                $block->prependInstruction($phi);

                // 局部 use 重写：在该块内（phi 之后的指令）将 oldVal 替换为 phiVal
                $this->rewriteUsesInBlock($block, $oldVal, $phiVal, $skipFirst = true);

                // 更新该块出口版本，使后继块的 phi 能引用到 phi 结果
                if (isset($this->blockExitVersions[$bid])) {
                    $this->blockExitVersions[$bid][$varName] = $phiVal;
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 语句构建
    // ─────────────────────────────────────────────────────────────

    /**
     * 构建一条语句（分发到具体处理函数）。
     */
    private function buildStmt(int $nodeIdx): void
    {
        $node = $this->ast->nodes[$nodeIdx];
        switch ($node['kind']) {
            case NodeKind::ReturnStmtNode:
                $this->buildReturn($nodeIdx);
                break;
            case NodeKind::AssignStmtNode:
                $this->buildAssign($nodeIdx);
                break;
            case NodeKind::EchoStmtNode:
                $this->buildEcho($nodeIdx);
                break;
            case NodeKind::ExprStmtNode:
                // 表达式语句：构建子表达式即可（副作用已生成）
                $this->buildExpr($this->ast->child($nodeIdx, 0));
                break;
            case NodeKind::IfStmtNode:
                $this->buildIf($nodeIdx);
                break;
            case NodeKind::WhileStmtNode:
                $this->buildWhile($nodeIdx);
                break;
            case NodeKind::NopStmtNode:
                // 空语句：什么都不做
                break;
            case NodeKind::BlockStmtNode:
                // 块语句：依次构建子语句
                foreach ($this->ast->children($nodeIdx) as $child) {
                    $this->buildStmt($child);
                }
                break;
            default:
                // 未显式支持的语句：尝试构建为表达式（容错）
                $this->buildExpr($nodeIdx);
                break;
        }
    }

    private function buildReturn(int $nodeIdx): void
    {
        $node = $this->ast->nodes[$nodeIdx];
        $hasExpr = $node['extra']['hasExpr'] ?? false;
        if ($hasExpr && $this->ast->childCount($nodeIdx) > 0) {
            $exprIdx = $this->ast->child($nodeIdx, 0);
            $val = $this->buildExpr($exprIdx);
            $this->func->appendInst($this->currentBlockId, new SSAInstruction(
                OpCode::RET, null, [$val],
            ));
        } else {
            $this->func->appendInst($this->currentBlockId, new SSAInstruction(
                OpCode::RET, null, [],
            ));
        }
    }

    private function buildAssign(int $nodeIdx): void
    {
        $node = $this->ast->nodes[$nodeIdx];
        $varName = (string)$node['value']; // 含 $
        $exprIdx = $this->ast->child($nodeIdx, 0);
        $rhsVal = $this->buildExpr($exprIdx);

        // 为变量创建新 SSA value（每次赋值产生新版本）
        $rhsType = $this->func->values[$rhsVal]->type ?? SSAType::int();
        $newVid = $this->newValueForVar($varName, $rhsType);

        // 显式 COPY 指令，便于 dump 与调试
        $this->func->appendInst($this->currentBlockId, new SSAInstruction(
            OpCode::COPY, $newVid, [$rhsVal],
        ));
    }

    private function buildEcho(int $nodeIdx): void
    {
        // echo expr1, expr2, ... → 每个表达式生成 CALL @echo
        $childCount = $this->ast->childCount($nodeIdx);
        for ($i = 0; $i < $childCount; $i++) {
            $exprIdx = $this->ast->child($nodeIdx, $i);
            $val = $this->buildExpr($exprIdx);
            $this->func->appendInst($this->currentBlockId, new SSAInstruction(
                OpCode::CALL, null, [$val], ['func' => 'echo'],
            ));
        }
    }

    private function buildIf(int $nodeIdx): void
    {
        $node = $this->ast->nodes[$nodeIdx];
        $condIdx = $this->ast->child($nodeIdx, 0);
        $thenCount = (int)($node['extra']['thenBodyCount'] ?? 0);
        $elseifCount = (int)($node['extra']['elseifCount'] ?? 0);
        $elseCount = (int)($node['extra']['elseBodyCount'] ?? 0);

        // 构建条件
        $condVal = $this->buildExpr($condIdx);

        // 计算 then / else body 的子节点范围
        // children 布局: [cond, ...thenBody(thenCount), ...elseif(elseifCount), ...elseBody(elseCount)]
        $thenStart = 1;
        $elseStart = 1 + $thenCount + $elseifCount;
        $childCount = $this->ast->childCount($nodeIdx);

        $thenStmts = [];
        for ($i = $thenStart; $i < $thenStart + $thenCount; $i++) {
            $thenStmts[] = $this->ast->child($nodeIdx, $i);
        }
        $elseStmts = [];
        for ($i = $elseStart; $i < $elseStart + $elseCount && $i < $childCount; $i++) {
            $elseStmts[] = $this->ast->child($nodeIdx, $i);
        }

        // 保存 if 之前的版本（用于恢复到 then/else 分支起点）
        $preIfVersion = $this->currentVersion;

        // 创建 then / else / merge 块
        $thenId = $this->func->newBlock('if.then');
        $elseId = $this->func->newBlock('if.else');
        $mergeId = $this->func->newBlock('if.merge');

        // 当前块发出 BR 指令
        $this->func->appendInst($this->currentBlockId, new SSAInstruction(
            OpCode::BR, null, [$condVal], ['then_block' => $thenId, 'else_block' => $elseId],
        ));
        $this->func->addEdge($this->currentBlockId, $thenId);
        $this->func->addEdge($this->currentBlockId, $elseId);
        $this->blockExitVersions[$this->currentBlockId] = $this->currentVersion;

        // 构建 then 块
        $this->currentVersion = $preIfVersion;
        $this->blockEntryVersions[$thenId] = $this->currentVersion;
        $this->currentBlockId = $thenId;
        foreach ($thenStmts as $stmt) {
            $this->buildStmt($stmt);
        }
        $this->ensureTerminator();
        if (!$this->currentBlockHasTerminator()) {
            $this->func->appendInst($this->currentBlockId, new SSAInstruction(
                OpCode::JMP, null, [], ['target_block' => $mergeId],
            ));
            $this->func->addEdge($thenId, $mergeId);
        }
        $this->blockExitVersions[$thenId] = $this->currentVersion;

        // 构建 else 块
        $this->currentVersion = $preIfVersion;
        $this->blockEntryVersions[$elseId] = $this->currentVersion;
        $this->currentBlockId = $elseId;
        foreach ($elseStmts as $stmt) {
            $this->buildStmt($stmt);
        }
        $this->ensureTerminator();
        if (!$this->currentBlockHasTerminator()) {
            $this->func->appendInst($this->currentBlockId, new SSAInstruction(
                OpCode::JMP, null, [], ['target_block' => $mergeId],
            ));
            $this->func->addEdge($elseId, $mergeId);
        }
        $this->blockExitVersions[$elseId] = $this->currentVersion;

        // 切换到 merge 块
        $this->currentVersion = $preIfVersion; // phi 插入会修正
        $this->blockEntryVersions[$mergeId] = $this->currentVersion;
        $this->currentBlockId = $mergeId;
    }

    private function buildWhile(int $nodeIdx): void
    {
        $node = $this->ast->nodes[$nodeIdx];
        $condIdx = $this->ast->child($nodeIdx, 0);
        $bodyCount = (int)($node['extra']['bodyCount'] ?? 0);

        $bodyStmts = [];
        for ($i = 1; $i < 1 + $bodyCount; $i++) {
            $bodyStmts[] = $this->ast->child($nodeIdx, $i);
        }

        $preWhileVersion = $this->currentVersion;

        // 创建 cond / body / exit 块
        $condId = $this->func->newBlock('while.cond');
        $bodyId = $this->func->newBlock('while.body');
        $exitId = $this->func->newBlock('while.exit');

        // 当前块 JMP 到 cond
        $this->ensureTerminator();
        $this->func->appendInst($this->currentBlockId, new SSAInstruction(
            OpCode::JMP, null, [], ['target_block' => $condId],
        ));
        $this->func->addEdge($this->currentBlockId, $condId);
        $this->blockExitVersions[$this->currentBlockId] = $this->currentVersion;

        // 构建 cond 块
        $this->currentVersion = $preWhileVersion;
        $this->blockEntryVersions[$condId] = $this->currentVersion;
        $this->currentBlockId = $condId;
        $condVal = $this->buildExpr($condIdx);
        $this->func->appendInst($this->currentBlockId, new SSAInstruction(
            OpCode::BR, null, [$condVal], ['then_block' => $bodyId, 'else_block' => $exitId],
        ));
        $this->func->addEdge($condId, $bodyId);
        $this->func->addEdge($condId, $exitId);
        $this->blockExitVersions[$condId] = $this->currentVersion;

        // 构建 body 块
        $this->currentVersion = $preWhileVersion;
        $this->blockEntryVersions[$bodyId] = $this->currentVersion;
        $this->currentBlockId = $bodyId;
        foreach ($bodyStmts as $stmt) {
            $this->buildStmt($stmt);
        }
        $this->ensureTerminator();
        if (!$this->currentBlockHasTerminator()) {
            $this->func->appendInst($this->currentBlockId, new SSAInstruction(
                OpCode::JMP, null, [], ['target_block' => $condId],
            ));
            $this->func->addEdge($bodyId, $condId);
        }
        $this->blockExitVersions[$bodyId] = $this->currentVersion;

        // 切换到 exit 块
        $this->currentVersion = $preWhileVersion; // phi 插入会修正
        $this->blockEntryVersions[$exitId] = $this->currentVersion;
        $this->currentBlockId = $exitId;
    }

    // ─────────────────────────────────────────────────────────────
    // 表达式构建（返回 value id）
    // ─────────────────────────────────────────────────────────────

    private function buildExpr(int $nodeIdx): int
    {
        $node = $this->ast->nodes[$nodeIdx];
        switch ($node['kind']) {
            case NodeKind::IntLiteralExpr:
                return $this->buildConstInt((int)$node['value']);

            case NodeKind::FloatLiteralExpr:
                return $this->buildConstFloat((float)$node['value']);

            case NodeKind::BoolLiteralExpr:
                return $this->buildConstBool((bool)$node['value']);

            case NodeKind::NullLiteralExpr:
                return $this->buildConstNull();

            case NodeKind::VariableExpr:
                return $this->getCurrentValue((string)$node['value']);

            case NodeKind::BinaryExpr:
                return $this->buildBinary($nodeIdx);

            case NodeKind::UnaryExpr:
                return $this->buildUnary($nodeIdx);

            case NodeKind::CallExpr:
                return $this->buildCall($nodeIdx);

            default:
                // 未知表达式节点：返回一个 int 0 占位值（容错）
                return $this->buildConstInt(0);
        }
    }

    private function buildConstInt(int $v): int
    {
        $vid = $this->func->newValue(SSAType::int());
        $this->func->appendInst($this->currentBlockId, new SSAInstruction(
            OpCode::CONST_INT, $vid, [], ['value' => $v],
        ));
        return $vid;
    }

    private function buildConstFloat(float $v): int
    {
        $vid = $this->func->newValue(SSAType::float());
        $this->func->appendInst($this->currentBlockId, new SSAInstruction(
            OpCode::CONST_FLOAT, $vid, [], ['value' => $v],
        ));
        return $vid;
    }

    private function buildConstBool(bool $v): int
    {
        $vid = $this->func->newValue(SSAType::bool());
        $this->func->appendInst($this->currentBlockId, new SSAInstruction(
            OpCode::CONST_BOOL, $vid, [], ['value' => $v],
        ));
        return $vid;
    }

    private function buildConstNull(): int
    {
        $vid = $this->func->newValue(SSAType::ptr(SSAType::void()));
        $this->func->appendInst($this->currentBlockId, new SSAInstruction(
            OpCode::CONST_NULL, $vid, [],
        ));
        return $vid;
    }

    private function buildBinary(int $nodeIdx): int
    {
        $node = $this->ast->nodes[$nodeIdx];
        $op = (string)$node['value'];
        $leftIdx = $this->ast->child($nodeIdx, 0);
        $rightIdx = $this->ast->child($nodeIdx, 1);
        $leftVal = $this->buildExpr($leftIdx);
        $rightVal = $this->buildExpr($rightIdx);

        // 比较运算结果类型为 bool，其余为操作数类型
        $isCompare = in_array($op, ['==', '!=', '<', '<=', '>', '>=', '===', '!==']);
        $resultType = $isCompare ? SSAType::bool() : ($this->func->values[$leftVal]->type ?? SSAType::int());

        $vid = $this->func->newValue($resultType);
        $opCode = $this->binaryOpCode($op);
        $this->func->appendInst($this->currentBlockId, new SSAInstruction(
            $opCode, $vid, [$leftVal, $rightVal],
        ));
        return $vid;
    }

    private function buildUnary(int $nodeIdx): int
    {
        $node = $this->ast->nodes[$nodeIdx];
        $op = (string)$node['value'];
        $exprIdx = $this->ast->child($nodeIdx, 0);
        $operandVal = $this->buildExpr($exprIdx);

        $operandType = $this->func->values[$operandVal]->type ?? SSAType::int();
        $vid = $this->func->newValue($operandType);
        $opCode = match ($op) {
            '-' => OpCode::NEG,
            '!' => OpCode::NOT,
            default => OpCode::COPY, // 容错：未知一元操作当作 COPY
        };
        $this->func->appendInst($this->currentBlockId, new SSAInstruction(
            $opCode, $vid, [$operandVal],
        ));
        return $vid;
    }

    private function buildCall(int $nodeIdx): int
    {
        $node = $this->ast->nodes[$nodeIdx];
        $funcName = (string)$node['value'];
        $argCount = (int)($node['extra']['argCount'] ?? 0);
        $hasCallee = $node['extra']['hasCallee'] ?? false;

        // children: [callee?, arg1, arg2, ...]
        $argStart = $hasCallee ? 1 : 0;
        $argVals = [];
        for ($i = 0; $i < $argCount; $i++) {
            $argIdx = $this->ast->child($nodeIdx, $argStart + $i);
            $argVals[] = $this->buildExpr($argIdx);
        }

        // 调用结果类型：简化为 int（无精确返回类型推导）
        $vid = $this->func->newValue(SSAType::int());
        $this->func->appendInst($this->currentBlockId, new SSAInstruction(
            OpCode::CALL, $vid, $argVals, ['func' => $funcName],
        ));
        return $vid;
    }

    // ─────────────────────────────────────────────────────────────
    // 变量版本管理
    // ─────────────────────────────────────────────────────────────

    /**
     * 获取变量当前的 SSA value id。
     * 若变量未定义（未声明/未赋值），创建一个占位 int value。
     */
    private function getCurrentValue(string $varName): int
    {
        if (array_key_exists($varName, $this->currentVersion)) {
            return $this->currentVersion[$varName];
        }
        // 未定义变量：创建占位值（容错，PHP 动态语义）
        $vid = $this->func->newValue(SSAType::int(), $this->varDisplayName($varName));
        $this->currentVersion[$varName] = $vid;
        return $vid;
    }

    /**
     * 为变量创建新版本 SSA value，更新映射，并记录到 assignedVars。
     */
    private function newValueForVar(string $varName, SSAType $type): int
    {
        $vid = $this->func->newValue($type, $this->varDisplayName($varName));
        $this->currentVersion[$varName] = $vid;
        $this->assignedVars[$varName] = true;
        return $vid;
    }

    // ─────────────────────────────────────────────────────────────
    // 辅助方法
    // ─────────────────────────────────────────────────────────────

    /**
     * 将源语言类型字符串映射为 SSAType。
     */
    private function typeFromString(string $s): SSAType
    {
        $s = trim($s);
        // 去掉 C. 前缀（如 C.FILE）
        if (str_starts_with($s, 'C.')) {
            return SSAType::ptr(SSAType::void());
        }
        return match (strtolower($s)) {
            'int', 'int8', 'int16', 'int32', 'int64',
            'uint8', 'uint16', 'uint32', 'uint64',
            'char', 'byte', 'short', 'long', 'size_t' => SSAType::int(),
            'float', 'double', 'number' => SSAType::float(),
            'bool', 'boolean' => SSAType::bool(),
            'void', '' => SSAType::void(),
            'string' => SSAType::ptr(SSAType::int()),
            default => SSAType::int(), // 未知类型默认 int
        };
    }

    /**
     * 变量名显示形式：去掉前导 $。
     */
    private function varDisplayName(string $name): string
    {
        return preg_replace('/^\$+/', '', $name);
    }

    /**
     * 二元操作符 → OpCode 映射。
     */
    private function binaryOpCode(string $op): OpCode
    {
        return match ($op) {
            '+' => OpCode::ADD,
            '-' => OpCode::SUB,
            '*' => OpCode::MUL,
            '/' => OpCode::DIV,
            '%' => OpCode::MOD,
            '==' => OpCode::EQ,
            '!=' => OpCode::NE,
            '<' => OpCode::LT,
            '<=' => OpCode::LE,
            '>' => OpCode::GT,
            '>=' => OpCode::GE,
            '&&' => OpCode::AND,
            '||' => OpCode::OR,
            default => OpCode::ADD, // 容错
        };
    }

    /**
     * 确保当前块未以终结指令收尾时，不重复补 terminator。
     * （仅在 if/while 控制流转移前调用，避免重复 RET/JMP）
     */
    private function ensureTerminator(): void
    {
        // 空实现：控制流由调用方显式发出 BR/JMP
        // 此方法保留作为扩展点，当前不主动补 RET
    }

    /**
     * 当前块是否已以终结指令（RET/BR/JMP）收尾。
     * 用于避免在已 return 的分支后再追加 JMP（产生死代码与无效 CFG 边）。
     */
    private function currentBlockHasTerminator(): bool
    {
        $block = $this->func->blocks[$this->currentBlockId];
        if (count($block->instructions) === 0) {
            return false;
        }
        $last = end($block->instructions);
        return $last->op->isTerminator();
    }

    /**
     * 在块内重写指令操作数：将 oldValId 替换为 newValId。
     *
     * @param SSABasicBlock $block
     * @param int           $oldVal
     * @param int           $newVal
     * @param bool          $skipFirst  是否跳过第一条指令（phi 插入时跳过 phi 自身）
     */
    private function rewriteUsesInBlock(SSABasicBlock $block, int $oldVal, int $newVal, bool $skipFirst = false): void
    {
        $start = $skipFirst ? 1 : 0;
        $count = count($block->instructions);
        for ($i = $start; $i < $count; $i++) {
            $inst = $block->instructions[$i];
            foreach ($inst->operands as $k => $operand) {
                if ($operand === $oldVal) {
                    $inst->operands[$k] = $newVal;
                }
            }
        }
    }

    /**
     * 重置构建器状态（每次 build 调用前）。
     */
    private function resetState(): void
    {
        $this->currentBlockId = -1;
        $this->currentVersion = [];
        $this->blockEntryVersions = [];
        $this->blockExitVersions = [];
        $this->assignedVars = [];
    }
}
