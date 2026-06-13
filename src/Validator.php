<?php

declare(strict_types=1);

namespace Tphp;

use Tphp\AST\{
    ProgramNode, TphpType,
    ExprNode, FuncCallNode, BinaryOpNode, IndexAccessNode, StringRangeNode,
    ArrayLiteralNode, ClosureNode, ExprCallNode, CastExprNode, MethodCallNode,
    StmtNode, VarDeclNode, ExprStmtNode, PrintStmtNode, ReturnStmtNode,
    IfStmtNode, WhileStmtNode, ForStmtNode, SwitchStmtNode, ArrayAppendStmtNode,
};

/**
 * Semantic validation: checks function existence, import rules, entry point.
 *
 * Stateless — operates on ProgramNode trees and passes errors by reference.
 */
final class Validator
{
    /**
     * Validate the merged program: main() exists and returns void.
     *
     * @param string[] $errors
     */
    public static function validateEntryPoint(ProgramNode $program, array &$errors): void
    {
        $hasMain = false;
        foreach ($program->functions as $fn) {
            if ($fn->name === 'main' || $fn->name === 'Main\\main') {
                $hasMain = true;
                if ($fn->returnType !== TphpType::Void) {
                    $errors[] = "Error: main() must return void";
                }
            }
        }
        if (!$hasMain) {
            $errors[] = "Error: namespace Main must contain function main(): void";
        }
    }

    /**
     * Validate a single file's function calls against the known function set.
     *
     * @param array<string, true>  $knownFqns  known function fully-qualified names
     * @param array<string, string> $importMap  short alias → FQN
     * @param string[] $errors
     */
    public static function validateFile(
        string $file,
        ProgramNode $program,
        array $knownFqns,
        array $importMap,
        array &$errors,
    ): void {
        $builtins = ['strlen', 'count', 'var_dump', 'print', 'echo', 'unset', 'array',
            'CStr', 'CInt', 'CFloat', 'CBool', 'TInt', 'TFloat', 'TBool', 'TStr'];
        $ns = $program->namespace ?? '';

        // Forbid importing from Main namespace
        foreach ($program->imports as $import) {
            $parts = explode('\\', $import->fullName);
            if ($parts[0] === 'Main') {
                $errors[] = "Error: {$file}:{$import->line} Cannot import from Main namespace";
            }
        }

        $calls = [];
        foreach ($program->functions as $fn) {
            self::collectFuncCalls($fn->body, $calls);
        }

        foreach ($calls as $call) {
            $name = $call->name;
            if (in_array($name, $builtins, true)) { continue; }
            if (str_starts_with($name, '$')) { continue; } // closure variable call

            if ($name === 'main') {
                $errors[] = "Error: {$file}:{$call->line} Cannot call main(), it is the entry point";
                continue;
            }

            // Resolution: import → same-namespace → global
            $resolved = $importMap[$name] ?? null;

            if ($resolved === null && $ns !== '') {
                $resolved = $ns . '\\' . $name;
            }

            if (($resolved === null || !isset($knownFqns[$resolved])) && isset($knownFqns[$name])) {
                $resolved = $name;
            }

            if ($resolved === null || !isset($knownFqns[$resolved])) {
                $errors[] = "Error: {$file}:{$call->line} Undefined function '{$name}'";
            }
        }
    }

    // ---- AST walk helpers ----

    /** @param StmtNode[] $body */
    private static function collectFuncCalls(array $body, array &$calls): void
    {
        foreach ($body as $stmt) {
            self::walkStmt($stmt, $calls);
        }
    }

    private static function walkStmt(StmtNode $stmt, array &$calls): void
    {
        if ($stmt instanceof VarDeclNode) {
            self::walkExpr($stmt->init, $calls);
        } elseif ($stmt instanceof ExprStmtNode) {
            self::walkExpr($stmt->expr, $calls);
        } elseif ($stmt instanceof PrintStmtNode) {
            foreach ($stmt->args as $arg) { self::walkExpr($arg, $calls); }
        } elseif ($stmt instanceof ReturnStmtNode) {
            if ($stmt->expr !== null) self::walkExpr($stmt->expr, $calls);
        } elseif ($stmt instanceof IfStmtNode) {
            self::walkExpr($stmt->condition, $calls);
            self::collectFuncCalls($stmt->thenBody, $calls);
            self::collectFuncCalls($stmt->elseBody, $calls);
        } elseif ($stmt instanceof WhileStmtNode) {
            self::walkExpr($stmt->condition, $calls);
            self::collectFuncCalls($stmt->body, $calls);
        } elseif ($stmt instanceof ForStmtNode) {
            if ($stmt->init !== null) self::walkStmt($stmt->init, $calls);
            if ($stmt->condition !== null) self::walkExpr($stmt->condition, $calls);
            if ($stmt->increment !== null) self::walkExpr($stmt->increment, $calls);
            self::collectFuncCalls($stmt->body, $calls);
        } elseif ($stmt instanceof SwitchStmtNode) {
            self::walkExpr($stmt->subject, $calls);
            foreach ($stmt->cases as $case) {
                self::walkExpr($case->condition, $calls);
                self::collectFuncCalls($case->body, $calls);
            }
            self::collectFuncCalls($stmt->defaultBody, $calls);
        } elseif ($stmt instanceof ArrayAppendStmtNode) {
            self::walkExpr($stmt->value, $calls);
        }
    }

    private static function walkExpr(ExprNode $e, array &$calls): void
    {
        if ($e instanceof FuncCallNode) {
            $calls[] = $e;
            foreach ($e->args as $arg) { self::walkExpr($arg, $calls); }
        } elseif ($e instanceof BinaryOpNode) {
            self::walkExpr($e->left, $calls);
            self::walkExpr($e->right, $calls);
        } elseif ($e instanceof IndexAccessNode) {
            self::walkExpr($e->target, $calls);
            self::walkExpr($e->index, $calls);
        } elseif ($e instanceof StringRangeNode) {
            self::walkExpr($e->target, $calls);
            self::walkExpr($e->start, $calls);
            self::walkExpr($e->end, $calls);
        } elseif ($e instanceof ArrayLiteralNode) {
            foreach ($e->elements as $el) { self::walkExpr($el, $calls); }
        } elseif ($e instanceof ClosureNode) {
            self::collectFuncCalls($e->body, $calls);
        } elseif ($e instanceof ExprCallNode) {
            self::walkExpr($e->callee, $calls);
            foreach ($e->args as $arg) { self::walkExpr($arg, $calls); }
        } elseif ($e instanceof CastExprNode) {
            self::walkExpr($e->operand, $calls);
        } elseif ($e instanceof MethodCallNode) {
            foreach ($e->args as $arg) { self::walkExpr($arg, $calls); }
        }
    }
}
