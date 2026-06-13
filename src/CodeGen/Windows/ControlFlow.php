<?php

declare(strict_types=1);

namespace Tphp\CodeGen\Windows;

use Tphp\X64Builder;
use Tphp\AST\{
    TphpType, ExprNode, StmtNode,
    VarDeclNode, ExprStmtNode, PrintStmtNode, ReturnStmtNode,
    IfStmtNode, WhileStmtNode, ForStmtNode, SwitchStmtNode, SwitchCaseNode,
    BreakStmtNode, ArrayAppendStmtNode, UnsetStmtNode,
    IntegerLiteralNode, FloatLiteralNode, StringLiteralNode,
    BoolLiteralNode, NullLiteralNode, VarRefNode, ConstRefNode,
    BinaryOpNode, FuncCallNode, IndexAccessNode, StringRangeNode,
    ArrayLiteralNode, ClosureNode, ExprCallNode, PostIncrementNode,
    CastExprNode, MethodCallNode, ThisExprNode, NewExprNode, EnumAccessNode,
    FunctionDeclNode, ParamNode, ClassDeclNode, MethodDeclNode,
    ExternFuncNode, CFuncCallNode, ConstDeclNode,
};

/**
 * Extracted from CodeGeneratorWindows
 */
trait ControlFlow
{

    private function genIf(IfStmtNode $s): void
    {
        $elseL = $this->newLabel('.L.else');
        $endL  = $this->newLabel('.L.endif');

        $this->genExpr($s->condition);
        $this->b->testRR(X64Builder::RAX, X64Builder::RAX);
        $this->b->jeLabel($elseL);

        foreach ($s->thenBody as $st) $this->genStmt($st);
        $this->b->jmpLabel($endL);

        $this->b->defineLabel($elseL);
        foreach ($s->elseBody as $st) $this->genStmt($st);

        $this->b->defineLabel($endL);
    }

    private function genWhile(WhileStmtNode $s): void
    {
        $startL = $this->newLabel('.L.while');
        $endL   = $this->newLabel('.L.endw');

        $this->b->defineLabel($startL);
        $this->genExpr($s->condition);
        $this->b->testRR(X64Builder::RAX, X64Builder::RAX);
        $this->b->jeLabel($endL);

        foreach ($s->body as $st) $this->genStmt($st);
        $this->b->jmpLabel($startL);

        $this->b->defineLabel($endL);
    }

    // ==================== For ====================

    private function genFor(ForStmtNode $s): void
    {
        $startL = $this->newLabel('.L.for.start');
        $endL   = $this->newLabel('.L.for.end');

        // Init
        if ($s->init !== null) {
            $this->genStmt($s->init);
        }

        // Push break label
        $this->breakLabels[] = $endL;

        $this->b->defineLabel($startL);

        // Condition
        if ($s->condition !== null) {
            $this->genExpr($s->condition);
            $this->b->testRR(X64Builder::RAX, X64Builder::RAX);
            $this->b->jeLabel($endL);
        }

        // Body
        foreach ($s->body as $st) $this->genStmt($st);

        // Increment
        if ($s->increment !== null) {
            $this->genExpr($s->increment);
        }

        $this->b->jmpLabel($startL);
        $this->b->defineLabel($endL);

        // Pop break label
        array_pop($this->breakLabels);
    }

    // ==================== Switch ====================

    private function genSwitch(SwitchStmtNode $s): void
    {
        $endL = $this->newLabel('.L.sw.end');

        // Push break label
        $this->breakLabels[] = $endL;

        // Evaluate subject, save on stack
        $this->genExpr($s->subject);
        $this->b->pushReg(X64Builder::RAX);  // stash subject on stack

        // Create labels for each case
        $caseLabels = [];
        foreach ($s->cases as $case) {
            $caseLabels[] = $this->newLabel('.L.sw.case');
        }
        $defaultLabel = $this->newLabel('.L.sw.def');

        // Comparison chain: for each case, compare subject with case value
        foreach ($s->cases as $i => $case) {
            $this->b->popReg(X64Builder::RCX);      // restore subject
            $this->b->pushReg(X64Builder::RCX);     // re-save for next comparison
            $this->genExpr($case->condition);       // case value → RAX
            $this->b->cmpRR(X64Builder::RCX, X64Builder::RAX);
            $this->b->jeLabel($caseLabels[$i]);
        }

        // Clean up stack (pop the saved subject)
        $this->b->emit("\x48\x83\xC4\x08");          // add rsp, 8

        // No match: jump to default (or end if no default)
        if (count($s->defaultBody) > 0) {
            $this->b->jmpLabel($defaultLabel);
        }
        $this->b->jmpLabel($endL);

        // Generate case bodies
        foreach ($s->cases as $i => $case) {
            $this->b->defineLabel($caseLabels[$i]);
            foreach ($case->body as $st) {
                $this->genStmt($st);
            }
            // No extra jmp here — fall-through behavior unless break was encountered
        }

        // Default body
        if (count($s->defaultBody) > 0) {
            $this->b->defineLabel($defaultLabel);
            foreach ($s->defaultBody as $st) {
                $this->genStmt($st);
            }
        }

        $this->b->defineLabel($endL);

        // Pop break label
        array_pop($this->breakLabels);
    }

    // ==================== Break ====================

    private function genBreak(BreakStmtNode $s): void
    {
        $target = end($this->breakLabels);
        if ($target === false) {
            throw new \RuntimeException("break outside loop or switch at line {$s->line}");
        }
        $this->b->jmpLabel($target);
    }

    // ==================== PostIncrement / PostDecrement ====================

    private function genPostIncrement(PostIncrementNode $e): void
    {
        $varName = $e->varName;
        $info = $this->calleeVars[$varName] ?? $this->vars[$varName] ?? null;
        if ($info === null) {
            throw new \RuntimeException("Undefined variable: \${$varName}, line {$e->line}");
        }
        $offset = $info['offset'];

        // Load value, push original
        $this->b->movRM64(X64Builder::RAX, X64Builder::RBP, $offset);
        $this->b->pushReg(X64Builder::RAX);  // save original

        // Increment/decrement
        if ($e->isDecrement) {
            $this->b->subRI32(X64Builder::RAX, 1);
        } else {
            $this->b->emitAddRI32(X64Builder::RAX, 1);
        }
        $this->b->movMR64(X64Builder::RBP, $offset, X64Builder::RAX);  // store back

        // Return original value (pop into RAX)
        $this->b->popReg(X64Builder::RAX);
    }

    // FFI methods are in CodeGen\Windows\FFI trait
}
