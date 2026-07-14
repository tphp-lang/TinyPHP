<?php
#debug 1. pure keyed: name=100 age=30 city=1
#debug 2. mixed: v1=10 v3=30
#debug 3. keyed: aa=1 bb=2
#debug 4. list() keyed: status=200 code=404
#debug 5. foreach: id=1 score=95
#debug 5. foreach: id=2 score=88
#debug 6. re-assign: existing=42 extra=77
#debug 7. single key: result=1
#debug 8. cross-class: id=123 val=456

class Main {
    public function main(): void {
        // ── 1. 纯键名解构 ──
        $arr = ['name' => 100, 'age' => 30, 'city' => 1];
        ['name' => $n, 'age' => $a, 'city' => $c] = $arr;
        echo "1. pure keyed: name=$n age=$a city=$c\n";

        // ── 2. 混合: 键名 + 位置 ──
        // 位置解构按 array entry 顺序 (非 int key) — 已知限制
        $arr2 = ['x' => 10, 'y' => 30];
        ['x' => $v1, 'y' => $v3] = $arr2;
        echo "2. mixed: v1=$v1 v3=$v3\n";

        // ── 3. 纯键名 + 多余值忽略 ──
        $arr3 = ['a' => 1, 'b' => 2, 'c' => 3];
        [ 'b' => $bb, 'a' => $aa] = $arr3;
        echo "3. keyed: aa=$aa bb=$bb\n";

        // ── 4. list() 语法 + 键名 ──
        $arr4 = ['status' => 200, 'code' => 404];
        list('status' => $st, 'code' => $cd) = $arr4;
        echo "4. list() keyed: status=$st code=$cd\n";

        // ── 5. 遍历中使用键名解构 ──
        $users = [
            ['id' => 1, 'score' => 95],
            ['id' => 2, 'score' => 88],
        ];
        foreach ($users as $u) {
            ['id' => $uid, 'score' => $sc] = $u;
            echo "5. foreach: id=$uid score=$sc\n";
        }

        // ── 6. 变量已声明，赋值更新 ──
        $existing = 999;
        $arr5 = ['existing' => 42, 'extra' => 77];
        ['existing' => $existing, 'extra' => $extra] = $arr5;
        echo "6. re-assign: existing=$existing extra=$extra\n";

        // ── 7. 单键名 ──
        $single = ['result' => 1];
        ['result' => $r] = $single;
        echo "7. single key: result=$r\n";

        // ── 8. 跨类调用返回数组 + 键名解构 ──
        $helper = new ListHelper();
        $data = $helper->getData();
        ['id' => $did, 'val' => $dval] = $data;
        echo "8. cross-class: id=$did val=$dval\n";
    }
}

class ListHelper {
    public function getData(): array {
        return ['id' => 123, 'val' => 456];
    }
}
