<?php
// calendar 扩展测试 — 4 种日历系统 + Easter 算法 + 异常处理
// 参考 PHP 8.5.7 ext/calendar 的输出值进行对比验证
#import calendar

#debug === Calendar Test ===
#debug
#debug -- Constants --
#debug CAL_GREGORIAN: 0
#debug CAL_JULIAN: 1
#debug CAL_JEWISH: 2
#debug CAL_FRENCH: 3
#debug CAL_NUM_CALS: 4
#debug
#debug -- Gregorian --
#debug gregoriantojd(1,1,2024): 2460311
#debug gregoriantojd(3,15,2025): 2460750
#debug jdtogregorian(2460311): 1/1/2024
#debug jdtogregorian(2460750): 3/15/2025
#debug
#debug -- Julian --
#debug juliantojd(1,1,2024): 2460324
#debug juliantojd(3,15,2025): 2460763
#debug jdtojulian(2460324): 1/1/2024
#debug jdtojulian(2460750): 3/2/2025
#debug
#debug -- Jewish --
#debug jewishtojd(1,1,5784): 2460204
#debug jewishtojd(7,15,5784): 2460395
#debug jdtojewish(2460311): 4/20/5784
#debug jdtojewish(2460750): 7/15/5785
#debug jdtojewish_str(2460311): 20 Tevet 5784
#debug jdtojewish_str(2460750): 15 Adar 5785
#debug jewish_month_name(1): Tishri
#debug jewish_month_name(7): Adar II
#debug jewish_month_name(13): Elul
#debug
#debug -- French --
#debug frenchtojd(1,1,1): 2375840
#debug frenchtojd(13,5,14): 2380952
#debug jdtofrench(2375840): 1/1/1
#debug jdtofrench(2380952): 13/5/14
#debug
#debug -- cal_days_in_month --
#debug GREG 2/2024: 29
#debug GREG 2/2023: 28
#debug JUL 2/2024: 29
#debug JEW 1/5784: 30
#debug FRE 1/1: 30
#debug
#debug -- cal_from_jd --
#debug GREG 2460750: 3/15/2025 Saturday March
#debug JUL 2460750: 3/2/2025 Saturday March
#debug JEW 2460750: 7/15/5785 Saturday Adar
#debug FRE 2375840: 1/1/1 Saturday Vendemiaire
#debug
#debug -- cal_to_jd --
#debug GREG 3/15/2025: 2460750
#debug JUL 3/15/2025: 2460763
#debug JEW 4/20/5784: 2460311
#debug FRE 1/1/1: 2375840
#debug
#debug -- cal_info --
#debug GREG name: Gregorian
#debug GREG symbol: CAL_GREGORIAN
#debug GREG maxdays: 31
#debug GREG month_1: January
#debug JEW name: Jewish
#debug JEW month_1: Tishri
#debug FRE name: French
#debug FRE month_1: Vendemiaire
#debug
#debug -- Easter --
#debug easter_days(2024): 10
#debug easter_days(1990): 25
#debug easter_days(2000): 33
#debug easter_days(2025): 30
#debug easter_days(1970): 8
#debug easter_date(2024): 2024-03-31
#debug easter_date(2025): 2025-04-20
#debug
#debug -- Exceptions --
#debug gregoriantojd(13,1,2024): caught
#debug frenchtojd(1,1,15): caught
#debug cal_days_in_month(5,1,2024): caught
#debug cal_from_jd(0,5): caught
#debug cal_to_jd(5,1,1,2024): caught
#debug cal_info(5): caught
#debug easter_date(1969): caught
#debug easter_days(0): caught
#debug jdtogregorian(0): caught
#debug jdtojewish(0): caught
#debug jdtofrench(0): caught
#debug jdtofrench(2380953): caught
#debug
#debug -- Round-trip --
#debug GREG rt: 2460750
#debug JUL rt: 2460763
#debug JEW rt: 2460311
#debug FRE rt: 2375840
#debug
#debug === All passed ===

class Main {
    public function main(): void {
        echo "=== Calendar Test ===\n\n";

        // ── 常量 ──
        echo "-- Constants --\n";
        echo "CAL_GREGORIAN: " . CAL_GREGORIAN . "\n";
        echo "CAL_JULIAN: " . CAL_JULIAN . "\n";
        echo "CAL_JEWISH: " . CAL_JEWISH . "\n";
        echo "CAL_FRENCH: " . CAL_FRENCH . "\n";
        echo "CAL_NUM_CALS: " . CAL_NUM_CALS . "\n";

        // ── 公历 ──
        echo "\n-- Gregorian --\n";
        echo "gregoriantojd(1,1,2024): " . gregoriantojd(1, 1, 2024) . "\n";
        echo "gregoriantojd(3,15,2025): " . gregoriantojd(3, 15, 2025) . "\n";
        $g1 = jdtogregorian(2460311);
        echo "jdtogregorian(2460311): " . $g1["month"] . "/" . $g1["day"] . "/" . $g1["year"] . "\n";
        $g2 = jdtogregorian(2460750);
        echo "jdtogregorian(2460750): " . $g2["month"] . "/" . $g2["day"] . "/" . $g2["year"] . "\n";

        // ── 儒略历 ──
        echo "\n-- Julian --\n";
        echo "juliantojd(1,1,2024): " . juliantojd(1, 1, 2024) . "\n";
        echo "juliantojd(3,15,2025): " . juliantojd(3, 15, 2025) . "\n";
        $j1 = jdtojulian(2460324);
        echo "jdtojulian(2460324): " . $j1["month"] . "/" . $j1["day"] . "/" . $j1["year"] . "\n";
        $j2 = jdtojulian(2460750);
        echo "jdtojulian(2460750): " . $j2["month"] . "/" . $j2["day"] . "/" . $j2["year"] . "\n";

        // ── 犹太历 ──
        echo "\n-- Jewish --\n";
        echo "jewishtojd(1,1,5784): " . jewishtojd(1, 1, 5784) . "\n";
        echo "jewishtojd(7,15,5784): " . jewishtojd(7, 15, 5784) . "\n";
        $w1 = jdtojewish(2460311);
        echo "jdtojewish(2460311): " . $w1["month"] . "/" . $w1["day"] . "/" . $w1["year"] . "\n";
        $w2 = jdtojewish(2460750);
        echo "jdtojewish(2460750): " . $w2["month"] . "/" . $w2["day"] . "/" . $w2["year"] . "\n";
        echo "jdtojewish_str(2460311): " . jdtojewish_str(2460311) . "\n";
        echo "jdtojewish_str(2460750): " . jdtojewish_str(2460750) . "\n";
        echo "jewish_month_name(1): " . jewish_month_name(1) . "\n";
        echo "jewish_month_name(7): " . jewish_month_name(7) . "\n";
        echo "jewish_month_name(13): " . jewish_month_name(13) . "\n";

        // ── 法国共和历 ──
        echo "\n-- French --\n";
        echo "frenchtojd(1,1,1): " . frenchtojd(1, 1, 1) . "\n";
        echo "frenchtojd(13,5,14): " . frenchtojd(13, 5, 14) . "\n";
        $f1 = jdtofrench(2375840);
        echo "jdtofrench(2375840): " . $f1["month"] . "/" . $f1["day"] . "/" . $f1["year"] . "\n";
        $f2 = jdtofrench(2380952);
        echo "jdtofrench(2380952): " . $f2["month"] . "/" . $f2["day"] . "/" . $f2["year"] . "\n";

        // ── cal_days_in_month ──
        echo "\n-- cal_days_in_month --\n";
        echo "GREG 2/2024: " . cal_days_in_month(CAL_GREGORIAN, 2, 2024) . "\n";
        echo "GREG 2/2023: " . cal_days_in_month(CAL_GREGORIAN, 2, 2023) . "\n";
        echo "JUL 2/2024: " . cal_days_in_month(CAL_JULIAN, 2, 2024) . "\n";
        echo "JEW 1/5784: " . cal_days_in_month(CAL_JEWISH, 1, 5784) . "\n";
        echo "FRE 1/1: " . cal_days_in_month(CAL_FRENCH, 1, 1) . "\n";

        // ── cal_from_jd ──
        echo "\n-- cal_from_jd --\n";
        $fg = cal_from_jd(2460750, CAL_GREGORIAN);
        echo "GREG 2460750: " . $fg["date"] . " " . $fg["dayname"] . " " . $fg["monthname"] . "\n";
        $fj = cal_from_jd(2460750, CAL_JULIAN);
        echo "JUL 2460750: " . $fj["date"] . " " . $fj["dayname"] . " " . $fj["monthname"] . "\n";
        $fw = cal_from_jd(2460750, CAL_JEWISH);
        echo "JEW 2460750: " . $fw["date"] . " " . $fw["dayname"] . " " . $fw["monthname"] . "\n";
        $ff = cal_from_jd(2375840, CAL_FRENCH);
        echo "FRE 2375840: " . $ff["date"] . " " . $ff["dayname"] . " " . $ff["monthname"] . "\n";

        // ── cal_to_jd ──
        echo "\n-- cal_to_jd --\n";
        echo "GREG 3/15/2025: " . cal_to_jd(CAL_GREGORIAN, 3, 15, 2025) . "\n";
        echo "JUL 3/15/2025: " . cal_to_jd(CAL_JULIAN, 3, 15, 2025) . "\n";
        echo "JEW 4/20/5784: " . cal_to_jd(CAL_JEWISH, 4, 20, 5784) . "\n";
        echo "FRE 1/1/1: " . cal_to_jd(CAL_FRENCH, 1, 1, 1) . "\n";

        // ── cal_info ──
        echo "\n-- cal_info --\n";
        $ig = cal_info(CAL_GREGORIAN);
        echo "GREG name: " . $ig["calname"] . "\n";
        echo "GREG symbol: " . $ig["calsymbol"] . "\n";
        echo "GREG maxdays: " . $ig["maxdaysinmonth"] . "\n";
        echo "GREG month_1: " . $ig["month_1"] . "\n";
        $iw = cal_info(CAL_JEWISH);
        echo "JEW name: " . $iw["calname"] . "\n";
        echo "JEW month_1: " . $iw["month_1"] . "\n";
        $fr_info = cal_info(CAL_FRENCH);
        echo "FRE name: " . $fr_info["calname"] . "\n";
        echo "FRE month_1: " . $fr_info["month_1"] . "\n";

        // ── Easter ──
        echo "\n-- Easter --\n";
        echo "easter_days(2024): " . easter_days(2024) . "\n";
        echo "easter_days(1990): " . easter_days(1990) . "\n";
        echo "easter_days(2000): " . easter_days(2000) . "\n";
        echo "easter_days(2025): " . easter_days(2025) . "\n";
        echo "easter_days(1970): " . easter_days(1970) . "\n";
        echo "easter_date(2024): " . date("Y-m-d", easter_date(2024)) . "\n";
        echo "easter_date(2025): " . date("Y-m-d", easter_date(2025)) . "\n";

        // ── 异常测试 ──
        echo "\n-- Exceptions --\n";
        try { gregoriantojd(13, 1, 2024); } catch (Exception $e) { echo "gregoriantojd(13,1,2024): caught\n"; }
        try { frenchtojd(1, 1, 15); } catch (Exception $e) { echo "frenchtojd(1,1,15): caught\n"; }
        try { cal_days_in_month(5, 1, 2024); } catch (Exception $e) { echo "cal_days_in_month(5,1,2024): caught\n"; }
        try { cal_from_jd(0, 5); } catch (Exception $e) { echo "cal_from_jd(0,5): caught\n"; }
        try { cal_to_jd(5, 1, 1, 2024); } catch (Exception $e) { echo "cal_to_jd(5,1,1,2024): caught\n"; }
        try { cal_info(5); } catch (Exception $e) { echo "cal_info(5): caught\n"; }
        try { easter_date(1969); } catch (Exception $e) { echo "easter_date(1969): caught\n"; }
        try { easter_days(0); } catch (Exception $e) { echo "easter_days(0): caught\n"; }
        try { jdtogregorian(0); } catch (Exception $e) { echo "jdtogregorian(0): caught\n"; }
        try { jdtojewish(0); } catch (Exception $e) { echo "jdtojewish(0): caught\n"; }
        try { jdtofrench(0); } catch (Exception $e) { echo "jdtofrench(0): caught\n"; }
        try { jdtofrench(2380953); } catch (Exception $e) { echo "jdtofrench(2380953): caught\n"; }

        // ── 往返转换 ──
        echo "\n-- Round-trip --\n";
        $rtg = jdtogregorian(gregoriantojd(3, 15, 2025));
        $rtg_jd = gregoriantojd($rtg["month"], $rtg["day"], $rtg["year"]);
        echo "GREG rt: " . $rtg_jd . "\n";
        $rtj = jdtojulian(juliantojd(3, 15, 2025));
        $rtj_jd = juliantojd($rtj["month"], $rtj["day"], $rtj["year"]);
        echo "JUL rt: " . $rtj_jd . "\n";
        $rtw = jdtojewish(jewishtojd(4, 20, 5784));
        $rtw_jd = jewishtojd($rtw["month"], $rtw["day"], $rtw["year"]);
        echo "JEW rt: " . $rtw_jd . "\n";
        $rtf = jdtofrench(frenchtojd(1, 1, 1));
        $rtf_jd = frenchtojd($rtf["month"], $rtf["day"], $rtf["year"]);
        echo "FRE rt: " . $rtf_jd . "\n";

        echo "\n=== All passed ===\n";
    }
}
