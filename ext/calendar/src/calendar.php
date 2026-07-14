<?php
// ext/calendar/src/calendar.php — 日历转换扩展（纯 tphp，AOT 编译）
//
// 公历 / 儒略历 / 犹太历 / 法国共和历 ↔ 儒略日 (Julian Day Number)
// 基于 PHP ext/calendar 的 C 算法翻译为 tphp，零外部依赖。
//
// AOT 设计要点：
//   1. JD→日历转换返回 array ["month","day","year"]（全 int），不返回 PHP 的 "m/d/y" 字符串
//   2. 无效日期 → throw Exception（不静默返回 0 或 "0/0/0"）
//   3. cal_from_jd 返回混合类型数组（int + string），由 t_var 统一存储
//   4. 纯整数算术，easter_date 调用 mktime 获取 Unix 时间戳
//   5. 犹太历 64 位直接算术（无需 C 源码的 32 位拆分溢出保护）

// ════════════════════════════════════════════════════════════
// 公共常量
// ════════════════════════════════════════════════════════════
const CAL_GREGORIAN = 0;
const CAL_JULIAN = 1;
const CAL_JEWISH = 2;
const CAL_FRENCH = 3;
const CAL_JEWISH_ADD_ALAFIM_GERESH = 4;
const CAL_NUM_CALS = 4;

// 复活节算法模式
const CAL_EASTER_DEFAULT = 0;
const CAL_EASTER_ROMAN = 1;
const CAL_EASTER_ALWAYS_GREGORIAN = 2;
const CAL_EASTER_ALWAYS_JULIAN = 3;

// ════════════════════════════════════════════════════════════
// 内部常量
// ════════════════════════════════════════════════════════════

// 犹太历常量
const CAL_HALAKIM_PER_HOUR = 1080;
const CAL_HALAKIM_PER_DAY = 25920;
const CAL_HALAKIM_PER_LUNAR_CYCLE = 765433;   // 29 * 25920 + 13753
const CAL_HALAKIM_PER_METONIC_CYCLE = 179876755; // 765433 * 235
const CAL_JEWISH_SDN_OFFSET = 347997;
const CAL_NEW_MOON_OF_CREATION = 31524;
const CAL_NOON = 19440;          // 18 * 1080
const CAL_AM3_11_20 = 9924;     // 9 * 1080 + 204
const CAL_AM9_32_43 = 16789;    // 15 * 1080 + 589

// 公历常量
const CAL_GREGOR_SDN_OFFSET = 32045;
const CAL_DAYS_PER_5_MONTHS = 153;
const CAL_DAYS_PER_4_YEARS = 1461;
const CAL_DAYS_PER_400_YEARS = 146097;

// 儒略历常量
const CAL_JULIAN_SDN_OFFSET = 32083;

// 法国共和历常量
const CAL_FRENCH_SDN_OFFSET = 2375474;
const CAL_FRENCH_FIRST_VALID = 2375840;
const CAL_FRENCH_LAST_VALID = 2380952;

// ════════════════════════════════════════════════════════════
// 内部辅助：星期计算
// ════════════════════════════════════════════════════════════

// JD → 星期 (0=Sun..6=Sat)
function _cal_day_of_week(int $sdn): int
{
    return ($sdn % 7 + 8) % 7;
}

// 星期缩写名
function _cal_day_name_short(int $dow): string
{
    if ($dow == 0) { return "Sun"; }
    if ($dow == 1) { return "Mon"; }
    if ($dow == 2) { return "Tue"; }
    if ($dow == 3) { return "Wed"; }
    if ($dow == 4) { return "Thu"; }
    if ($dow == 5) { return "Fri"; }
    return "Sat";
}

// 星期全名
function _cal_day_name_long(int $dow): string
{
    if ($dow == 0) { return "Sunday"; }
    if ($dow == 1) { return "Monday"; }
    if ($dow == 2) { return "Tuesday"; }
    if ($dow == 3) { return "Wednesday"; }
    if ($dow == 4) { return "Thursday"; }
    if ($dow == 5) { return "Friday"; }
    return "Saturday";
}

// ════════════════════════════════════════════════════════════
// 内部辅助：公历月份名
// ════════════════════════════════════════════════════════════

function _cal_gregorian_month_short(int $month): string
{
    if ($month == 1) { return "Jan"; }
    if ($month == 2) { return "Feb"; }
    if ($month == 3) { return "Mar"; }
    if ($month == 4) { return "Apr"; }
    if ($month == 5) { return "May"; }
    if ($month == 6) { return "Jun"; }
    if ($month == 7) { return "Jul"; }
    if ($month == 8) { return "Aug"; }
    if ($month == 9) { return "Sep"; }
    if ($month == 10) { return "Oct"; }
    if ($month == 11) { return "Nov"; }
    return "Dec";
}

function _cal_gregorian_month_long(int $month): string
{
    if ($month == 1) { return "January"; }
    if ($month == 2) { return "February"; }
    if ($month == 3) { return "March"; }
    if ($month == 4) { return "April"; }
    if ($month == 5) { return "May"; }
    if ($month == 6) { return "June"; }
    if ($month == 7) { return "July"; }
    if ($month == 8) { return "August"; }
    if ($month == 9) { return "September"; }
    if ($month == 10) { return "October"; }
    if ($month == 11) { return "November"; }
    return "December";
}

// ════════════════════════════════════════════════════════════
// 内部辅助：犹太历月份名 / 查找表
// ════════════════════════════════════════════════════════════

// 19 年默冬周期中每年的月数 (闰年=13, 平年=12)
function _cal_months_per_year(int $idx): int
{
    if ($idx == 0) { return 12; }
    if ($idx == 1) { return 12; }
    if ($idx == 2) { return 13; }
    if ($idx == 3) { return 12; }
    if ($idx == 4) { return 12; }
    if ($idx == 5) { return 13; }
    if ($idx == 6) { return 12; }
    if ($idx == 7) { return 13; }
    if ($idx == 8) { return 12; }
    if ($idx == 9) { return 12; }
    if ($idx == 10) { return 13; }
    if ($idx == 11) { return 12; }
    if ($idx == 12) { return 12; }
    if ($idx == 13) { return 13; }
    if ($idx == 14) { return 12; }
    if ($idx == 15) { return 12; }
    if ($idx == 16) { return 13; }
    if ($idx == 17) { return 12; }
    return 13;
}

// 默冬周期内年份偏移 (累计月数)
function _cal_year_offset(int $idx): int
{
    if ($idx == 0) { return 0; }
    if ($idx == 1) { return 12; }
    if ($idx == 2) { return 24; }
    if ($idx == 3) { return 37; }
    if ($idx == 4) { return 49; }
    if ($idx == 5) { return 61; }
    if ($idx == 6) { return 74; }
    if ($idx == 7) { return 86; }
    if ($idx == 8) { return 99; }
    if ($idx == 9) { return 111; }
    if ($idx == 10) { return 123; }
    if ($idx == 11) { return 136; }
    if ($idx == 12) { return 148; }
    if ($idx == 13) { return 160; }
    if ($idx == 14) { return 173; }
    if ($idx == 15) { return 185; }
    if ($idx == 16) { return 197; }
    if ($idx == 17) { return 210; }
    return 222;
}

// 犹太历月份名（闰年，13 个月）
function _cal_jewish_month_name_leap(int $month): string
{
    if ($month == 1) { return "Tishri"; }
    if ($month == 2) { return "Heshvan"; }
    if ($month == 3) { return "Kislev"; }
    if ($month == 4) { return "Tevet"; }
    if ($month == 5) { return "Shevat"; }
    if ($month == 6) { return "Adar I"; }
    if ($month == 7) { return "Adar II"; }
    if ($month == 8) { return "Nisan"; }
    if ($month == 9) { return "Iyyar"; }
    if ($month == 10) { return "Sivan"; }
    if ($month == 11) { return "Tammuz"; }
    if ($month == 12) { return "Av"; }
    if ($month == 13) { return "Elul"; }
    return "";
}

// 犹太历月份名（平年，12 个月）
function _cal_jewish_month_name_regular(int $month): string
{
    if ($month == 1) { return "Tishri"; }
    if ($month == 2) { return "Heshvan"; }
    if ($month == 3) { return "Kislev"; }
    if ($month == 4) { return "Tevet"; }
    if ($month == 5) { return "Shevat"; }
    if ($month == 7) { return "Adar"; }
    if ($month == 8) { return "Nisan"; }
    if ($month == 9) { return "Iyyar"; }
    if ($month == 10) { return "Sivan"; }
    if ($month == 11) { return "Tammuz"; }
    if ($month == 12) { return "Av"; }
    if ($month == 13) { return "Elul"; }
    return "";
}

// 犹太历月份名（根据年份判断闰/平年）
function _cal_jewish_month_name(int $month, int $year): string
{
    if (_cal_months_per_year(($year - 1) % 19) == 13) {
        return _cal_jewish_month_name_leap($month);
    }
    return _cal_jewish_month_name_regular($month);
}

// ════════════════════════════════════════════════════════════
// 内部辅助：法国共和历月份名
// ════════════════════════════════════════════════════════════

function _cal_french_month_name(int $month): string
{
    if ($month == 1) { return "Vendemiaire"; }
    if ($month == 2) { return "Brumaire"; }
    if ($month == 3) { return "Frimaire"; }
    if ($month == 4) { return "Nivose"; }
    if ($month == 5) { return "Pluviose"; }
    if ($month == 6) { return "Ventose"; }
    if ($month == 7) { return "Germinal"; }
    if ($month == 8) { return "Floreal"; }
    if ($month == 9) { return "Prairial"; }
    if ($month == 10) { return "Messidor"; }
    if ($month == 11) { return "Thermidor"; }
    if ($month == 12) { return "Fructidor"; }
    if ($month == 13) { return "Extra"; }
    return "";
}

// ════════════════════════════════════════════════════════════
// 公历 ↔ JD（内部实现，返回 0 表示无效）
// ════════════════════════════════════════════════════════════

function _cal_gregorian_to_sdn(int $year, int $month, int $day): int
{
    if ($year == 0 || $year < -4714 ||
        $month <= 0 || $month > 12 ||
        $day <= 0 || $day > 31) {
        return 0;
    }
    $y = 0;
    if ($year < 0) {
        $y = $year + 4801;
    } else {
        $y = $year + 4800;
    }
    $m = 0;
    if ($month > 2) {
        $m = $month - 3;
    } else {
        $m = $month + 9;
        $y = $y - 1;
    }
    return ($y / 100) * 146097 / 4
        + ($y % 100) * 1461 / 4
        + ($m * 153 + 2) / 5
        + $day
        - 32045;
}

function _cal_sdn_to_gregorian(int $sdn): array
{
    if ($sdn <= 0) {
        return ["month" => 0, "day" => 0, "year" => 0];
    }
    $temp = ($sdn + 32045) * 4 - 1;
    $century = $temp / 146097;
    $temp = ($temp % 146097) / 4 * 4 + 3;
    $year = $century * 100 + $temp / 1461;
    $dayOfYear = ($temp % 1461) / 4 + 1;
    $temp = $dayOfYear * 5 - 3;
    $month = $temp / 153;
    $day = ($temp % 153) / 5 + 1;
    if ($month < 10) {
        $month = $month + 3;
    } else {
        $year = $year + 1;
        $month = $month - 9;
    }
    $year = $year - 4800;
    if ($year <= 0) {
        $year = $year - 1;
    }
    return ["month" => $month, "day" => $day, "year" => $year];
}

// ════════════════════════════════════════════════════════════
// 儒略历 ↔ JD（内部实现）
// ════════════════════════════════════════════════════════════

function _cal_julian_to_sdn(int $year, int $month, int $day): int
{
    if ($year == 0 || $year < -4713 ||
        $month <= 0 || $month > 12 ||
        $day <= 0 || $day > 31) {
        return 0;
    }
    $y = 0;
    if ($year < 0) {
        $y = $year + 4801;
    } else {
        $y = $year + 4800;
    }
    $m = 0;
    if ($month > 2) {
        $m = $month - 3;
    } else {
        $m = $month + 9;
        $y = $y - 1;
    }
    return ($y * 1461) / 4
        + ($m * 153 + 2) / 5
        + $day
        - 32083;
}

function _cal_sdn_to_julian(int $sdn): array
{
    if ($sdn <= 0) {
        return ["month" => 0, "day" => 0, "year" => 0];
    }
    $temp = $sdn * 4 + (32083 * 4 - 1);
    $year = $temp / 1461;
    $dayOfYear = ($temp % 1461) / 4 + 1;
    $temp = $dayOfYear * 5 - 3;
    $month = $temp / 153;
    $day = ($temp % 153) / 5 + 1;
    if ($month < 10) {
        $month = $month + 3;
    } else {
        $year = $year + 1;
        $month = $month - 9;
    }
    $year = $year - 4800;
    if ($year <= 0) {
        $year = $year - 1;
    }
    return ["month" => $month, "day" => $day, "year" => $year];
}

// ════════════════════════════════════════════════════════════
// 法国共和历 ↔ JD（内部实现）
// ════════════════════════════════════════════════════════════

function _cal_french_to_sdn(int $year, int $month, int $day): int
{
    if ($year < 1 || $year > 14 ||
        $month < 1 || $month > 13 ||
        $day < 1 || $day > 30) {
        return 0;
    }
    return ($year * 1461) / 4
        + ($month - 1) * 30
        + $day
        + 2375474;
}

function _cal_sdn_to_french(int $sdn): array
{
    if ($sdn < 2375840 || $sdn > 2380952) {
        return ["month" => 0, "day" => 0, "year" => 0];
    }
    $temp = ($sdn - 2375474) * 4 - 1;
    $year = $temp / 1461;
    $dayOfYear = ($temp % 1461) / 4;
    $month = $dayOfYear / 30 + 1;
    $day = $dayOfYear % 30 + 1;
    return ["month" => $month, "day" => $day, "year" => $year];
}

// ════════════════════════════════════════════════════════════
// 犹太历 ↔ JD（内部实现）
// ════════════════════════════════════════════════════════════

// Tishri1 计算：给定默冬年号和 molad（新月时刻），返回实际新年第一天
function _cal_tishri1(int $metonicYear, int $moladDay, int $moladHalakim): int
{
    $tishri1 = $moladDay;
    $dow = $tishri1 % 7;
    $leapYear = ($metonicYear == 2 || $metonicYear == 5 || $metonicYear == 7
        || $metonicYear == 10 || $metonicYear == 13 || $metonicYear == 16
        || $metonicYear == 18);
    $lastWasLeapYear = ($metonicYear == 3 || $metonicYear == 6
        || $metonicYear == 8 || $metonicYear == 11 || $metonicYear == 14
        || $metonicYear == 17 || $metonicYear == 0);

    // 规则 2/3/4
    if (($moladHalakim >= 19440) ||
        (!$leapYear && $dow == 2 && $moladHalakim >= 9924) ||
        ($lastWasLeapYear && $dow == 1 && $moladHalakim >= 16789)) {
        $tishri1 = $tishri1 + 1;
        $dow = $dow + 1;
        if ($dow == 7) {
            $dow = 0;
        }
    }
    // 规则 1（可额外延迟一天）
    if ($dow == 3 || $dow == 5 || $dow == 0) {
        $tishri1 = $tishri1 + 1;
    }
    return $tishri1;
}

// 计算默冬周期的起始 molad（64 位直接算术，无需 32 位拆分）
function _cal_molad_of_metonic_cycle(int $metonicCycle): array
{
    $halakim = 31524 + $metonicCycle * 179876755;
    $day = $halakim / 25920;
    $halakim = $halakim % 25920;
    return ["day" => $day, "halakim" => $halakim];
}

// 给定天数，找到最近的 Tishri molad
function _cal_find_tishri_molad(int $inputDay): array
{
    $metonicCycle = ($inputDay + 310) / 6940;
    $m = _cal_molad_of_metonic_cycle($metonicCycle);
    $moladDay = $m["day"];
    $moladHalakim = $m["halakim"];

    // 修正低估
    while ($moladDay < $inputDay - 6940 + 310) {
        $metonicCycle = $metonicCycle + 1;
        $moladHalakim = $moladHalakim + 179876755;
        $moladDay = $moladDay + $moladHalakim / 25920;
        $moladHalakim = $moladHalakim % 25920;
    }

    // 找到最接近的 Tishri molad
    $metonicYear = 0;
    while ($metonicYear < 18) {
        if ($moladDay > $inputDay - 74) {
            break;
        }
        $moladHalakim = $moladHalakim + 765433 * _cal_months_per_year($metonicYear);
        $moladDay = $moladDay + $moladHalakim / 25920;
        $moladHalakim = $moladHalakim % 25920;
        $metonicYear = $metonicYear + 1;
    }

    return ["cycle" => $metonicCycle, "year" => $metonicYear,
            "day" => $moladDay, "halakim" => $moladHalakim];
}

// 给定年份，找到该年第一天的天数和起始 molad
function _cal_find_start_of_year(int $year): array
{
    $metonicCycle = ($year - 1) / 19;
    $metonicYear = ($year - 1) % 19;
    $m = _cal_molad_of_metonic_cycle($metonicCycle);
    $moladDay = $m["day"];
    $moladHalakim = $m["halakim"];

    $moladHalakim = $moladHalakim + 765433 * _cal_year_offset($metonicYear);
    $moladDay = $moladDay + $moladHalakim / 25920;
    $moladHalakim = $moladHalakim % 25920;

    $tishri1 = _cal_tishri1($metonicYear, $moladDay, $moladHalakim);

    return ["cycle" => $metonicCycle, "year" => $metonicYear,
            "day" => $moladDay, "halakim" => $moladHalakim,
            "tishri1" => $tishri1];
}

// 犹太历 → JD
function _cal_jewish_to_sdn(int $year, int $month, int $day): int
{
    if ($year <= 0 || $day <= 0 || $day > 30) {
        return 0;
    }

    $sdn = 0;
    if ($month == 1 || $month == 2) {
        // Tishri 或 Heshvan — 不需要年长度
        $s = _cal_find_start_of_year($year);
        $tishri1 = $s["tishri1"];
        if ($month == 1) {
            $sdn = $tishri1 + $day - 1;
        } else {
            $sdn = $tishri1 + $day + 29;
        }
    } elseif ($month == 3) {
        // Kislev — 必须计算年长度
        $s = _cal_find_start_of_year($year);
        $tishri1 = $s["tishri1"];
        $metonicYear = $s["year"];
        $moladDay = $s["day"];
        $moladHalakim = $s["halakim"];

        $moladHalakim = $moladHalakim + 765433 * _cal_months_per_year($metonicYear);
        $moladDay = $moladDay + $moladHalakim / 25920;
        $moladHalakim = $moladHalakim % 25920;
        $tishri1After = _cal_tishri1(($metonicYear + 1) % 19, $moladDay, $moladHalakim);

        $yearLength = $tishri1After - $tishri1;
        if ($yearLength == 355 || $yearLength == 385) {
            $sdn = $tishri1 + $day + 59;
        } else {
            $sdn = $tishri1 + $day + 58;
        }
    } elseif ($month == 4 || $month == 5 || $month == 6) {
        // Tevet, Shevat, Adar I — 不需要年长度
        $s = _cal_find_start_of_year($year + 1);
        $tishri1After = $s["tishri1"];

        $lengthOfAdarIAndII = 0;
        if (_cal_months_per_year(($year - 1) % 19) == 12) {
            $lengthOfAdarIAndII = 29;
        } else {
            $lengthOfAdarIAndII = 59;
        }

        if ($month == 4) {
            $sdn = $tishri1After + $day - $lengthOfAdarIAndII - 237;
        } elseif ($month == 5) {
            $sdn = $tishri1After + $day - $lengthOfAdarIAndII - 208;
        } else {
            $sdn = $tishri1After + $day - $lengthOfAdarIAndII - 178;
        }
    } elseif ($month >= 7 && $month <= 13) {
        // Adar II 或更后 — 不需要年长度
        $s = _cal_find_start_of_year($year + 1);
        $tishri1After = $s["tishri1"];

        if ($month == 7) {
            $sdn = $tishri1After + $day - 207;
        } elseif ($month == 8) {
            $sdn = $tishri1After + $day - 178;
        } elseif ($month == 9) {
            $sdn = $tishri1After + $day - 148;
        } elseif ($month == 10) {
            $sdn = $tishri1After + $day - 119;
        } elseif ($month == 11) {
            $sdn = $tishri1After + $day - 89;
        } elseif ($month == 12) {
            $sdn = $tishri1After + $day - 60;
        } else {
            $sdn = $tishri1After + $day - 30;
        }
    } else {
        return 0;
    }

    return $sdn + 347997;
}

// JD → 犹太历
function _cal_sdn_to_jewish(int $sdn): array
{
    if ($sdn <= 347997) {
        return ["month" => 0, "day" => 0, "year" => 0];
    }
    $inputDay = $sdn - 347997;

    $m = _cal_find_tishri_molad($inputDay);
    $day = $m["day"];
    $halakim = $m["halakim"];
    $metonicCycle = $m["cycle"];
    $metonicYear = $m["year"];
    $tishri1 = _cal_tishri1($metonicYear, $day, $halakim);

    $tishri1After = 0;
    $year = 0;

    if ($inputDay >= $tishri1) {
        // 找到年初的 Tishri 1
        $year = $metonicCycle * 19 + $metonicYear + 1;
        if ($inputDay < $tishri1 + 59) {
            if ($inputDay < $tishri1 + 30) {
                return ["month" => 1, "day" => $inputDay - $tishri1 + 1, "year" => $year];
            }
            return ["month" => 2, "day" => $inputDay - $tishri1 - 29, "year" => $year];
        }
        // 需要年长度：找下一年的 Tishri 1
        $halakim = $halakim + 765433 * _cal_months_per_year($metonicYear);
        $day = $day + $halakim / 25920;
        $halakim = $halakim % 25920;
        $tishri1After = _cal_tishri1(($metonicYear + 1) % 19, $day, $halakim);
    } else {
        // 找到年末的 Tishri 1
        $year = $metonicCycle * 19 + $metonicYear;
        if ($inputDay >= $tishri1 - 177) {
            // 年末 6 个月之一
            if ($inputDay > $tishri1 - 30) {
                return ["month" => 13, "day" => $inputDay - $tishri1 + 30, "year" => $year];
            }
            if ($inputDay > $tishri1 - 60) {
                return ["month" => 12, "day" => $inputDay - $tishri1 + 60, "year" => $year];
            }
            if ($inputDay > $tishri1 - 89) {
                return ["month" => 11, "day" => $inputDay - $tishri1 + 89, "year" => $year];
            }
            if ($inputDay > $tishri1 - 119) {
                return ["month" => 10, "day" => $inputDay - $tishri1 + 119, "year" => $year];
            }
            if ($inputDay > $tishri1 - 148) {
                return ["month" => 9, "day" => $inputDay - $tishri1 + 148, "year" => $year];
            }
            return ["month" => 8, "day" => $inputDay - $tishri1 + 178, "year" => $year];
        } else {
            $month = 0;
            $d = 0;
            if (_cal_months_per_year(($year - 1) % 19) == 13) {
                $month = 7;
                $d = $inputDay - $tishri1 + 207;
                if ($d > 0) { return ["month" => $month, "day" => $d, "year" => $year]; }
                $month = $month - 1;
                $d = $d + 30;
                if ($d > 0) { return ["month" => $month, "day" => $d, "year" => $year]; }
                $month = $month - 1;
                $d = $d + 30;
            } else {
                $month = 7;
                $d = $inputDay - $tishri1 + 207;
                if ($d > 0) { return ["month" => $month, "day" => $d, "year" => $year]; }
                $month = $month - 2;
                $d = $d + 30;
            }
            if ($d > 0) { return ["month" => $month, "day" => $d, "year" => $year]; }
            $month = $month - 1;
            $d = $d + 29;
            if ($d > 0) { return ["month" => $month, "day" => $d, "year" => $year]; }

            // 需要年长度：找这一年的 Tishri 1
            $tishri1After = $tishri1;
            $m2 = _cal_find_tishri_molad($day - 365);
            $day = $m2["day"];
            $halakim = $m2["halakim"];
            $metonicYear = $m2["year"];
            $tishri1 = _cal_tishri1($metonicYear, $day, $halakim);
        }
    }

    $yearLength = $tishri1After - $tishri1;
    $day = $inputDay - $tishri1 - 29;
    if ($yearLength == 355 || $yearLength == 385) {
        // Heshvan 有 30 天
        if ($day <= 30) {
            return ["month" => 2, "day" => $day, "year" => $year];
        }
        $day = $day - 30;
    } else {
        // Heshvan 有 29 天
        if ($day <= 29) {
            return ["month" => 2, "day" => $day, "year" => $year];
        }
        $day = $day - 29;
    }
    // 一定是 Kislev
    return ["month" => 3, "day" => $day, "year" => $year];
}

// ════════════════════════════════════════════════════════════
// 公共 API：公历
// ════════════════════════════════════════════════════════════

/**
 * gregoriantojd(int $month, int $day, int $year): int
 *
 * 公历转儒略日 (Julian Day Number)。
 */
function gregoriantojd(int $month, int $day, int $year): int|Exception
{
    $sdn = _cal_gregorian_to_sdn($year, $month, $day);
    if ($sdn == 0) {
        throw new Exception("gregoriantojd: invalid date");
    }
    return $sdn;
}

/**
 * jdtogregorian(int $jd): array
 *
 * 儒略日转公历，返回 ["month", "day", "year"]。
 */
function jdtogregorian(int $jd): array|Exception
{
    $r = _cal_sdn_to_gregorian($jd);
    if ($r["year"] == 0) {
        throw new Exception("jdtogregorian: JD out of range");
    }
    return $r;
}

// ════════════════════════════════════════════════════════════
// 公共 API：儒略历
// ════════════════════════════════════════════════════════════

/**
 * juliantojd(int $month, int $day, int $year): int
 *
 * 儒略历转儒略日。
 */
function juliantojd(int $month, int $day, int $year): int|Exception
{
    $sdn = _cal_julian_to_sdn($year, $month, $day);
    if ($sdn == 0) {
        throw new Exception("juliantojd: invalid date");
    }
    return $sdn;
}

/**
 * jdtojulian(int $jd): array
 *
 * 儒略日转儒略历，返回 ["month", "day", "year"]。
 */
function jdtojulian(int $jd): array|Exception
{
    $r = _cal_sdn_to_julian($jd);
    if ($r["year"] == 0) {
        throw new Exception("jdtojulian: JD out of range");
    }
    return $r;
}

// ════════════════════════════════════════════════════════════
// 公共 API：犹太历
// ════════════════════════════════════════════════════════════

/**
 * jewishtojd(int $month, int $day, int $year): int
 *
 * 犹太历转儒略日。
 * 月份: 1=Tishri, 2=Heshvan, ..., 6=Adar I(闰), 7=Adar II(闰)/Adar(平), ...
 */
function jewishtojd(int $month, int $day, int $year): int|Exception
{
    $sdn = _cal_jewish_to_sdn($year, $month, $day);
    if ($sdn == 0) {
        throw new Exception("jewishtojd: invalid date");
    }
    return $sdn;
}

/**
 * jdtojewish(int $jd): array
 *
 * 儒略日转犹太历，返回 ["month", "day", "year"]。
 */
function jdtojewish(int $jd): array|Exception
{
    $r = _cal_sdn_to_jewish($jd);
    if ($r["year"] == 0) {
        throw new Exception("jdtojewish: JD out of range");
    }
    return $r;
}

/**
 * jdtojewish_str(int $jd): string
 *
 * 返回带英文月份名的字符串 "day month_name year"。
 */
function jdtojewish_str(int $jd): string|Exception
{
    $r = _cal_sdn_to_jewish($jd);
    if ($r["year"] == 0) {
        throw new Exception("jdtojewish_str: JD out of range");
    }
    $name = _cal_jewish_month_name($r["month"], $r["year"]);
    return $r["day"] . " " . $name . " " . $r["year"];
}

/**
 * jewish_month_name(int $month): string
 *
 * 返回犹太历月份英文名（闰年版本）。
 */
function jewish_month_name(int $month): string
{
    return _cal_jewish_month_name_leap($month);
}

// ════════════════════════════════════════════════════════════
// 公共 API：法国共和历
// ════════════════════════════════════════════════════════════

/**
 * frenchtojd(int $month, int $day, int $year): int
 *
 * 法国共和历转儒略日。仅支持年份 1-14 (1792-1806)。
 */
function frenchtojd(int $month, int $day, int $year): int|Exception
{
    $sdn = _cal_french_to_sdn($year, $month, $day);
    if ($sdn == 0) {
        throw new Exception("frenchtojd: invalid date");
    }
    return $sdn;
}

/**
 * jdtofrench(int $jd): array
 *
 * 儒略日转法国共和历，返回 ["month", "day", "year"]。
 */
function jdtofrench(int $jd): array|Exception
{
    $r = _cal_sdn_to_french($jd);
    if ($r["year"] == 0) {
        throw new Exception("jdtofrench: JD out of range");
    }
    return $r;
}

// ════════════════════════════════════════════════════════════
// 公共 API：cal_days_in_month
// ════════════════════════════════════════════════════════════

/**
 * cal_days_in_month(int $calendar, int $month, int $year): int
 *
 * 返回指定日历/年/月的天数。
 */
function cal_days_in_month(int $calendar, int $month, int $year): int|Exception
{
    if ($calendar < 0 || $calendar >= 4) {
        throw new Exception("cal_days_in_month: invalid calendar ID");
    }
    if ($month <= 0) {
        throw new Exception("cal_days_in_month: invalid month");
    }

    $sdn_start = 0;
    $sdn_next = 0;

    if ($calendar == 0) {
        $sdn_start = _cal_gregorian_to_sdn($year, $month, 1);
        if ($sdn_start == 0) { throw new Exception("cal_days_in_month: invalid date"); }
        $sdn_next = _cal_gregorian_to_sdn($year, $month + 1, 1);
        if ($sdn_next == 0) {
            if ($year == -1) {
                $sdn_next = _cal_gregorian_to_sdn(1, 1, 1);
            } else {
                $sdn_next = _cal_gregorian_to_sdn($year + 1, 1, 1);
            }
        }
    } elseif ($calendar == 1) {
        $sdn_start = _cal_julian_to_sdn($year, $month, 1);
        if ($sdn_start == 0) { throw new Exception("cal_days_in_month: invalid date"); }
        $sdn_next = _cal_julian_to_sdn($year, $month + 1, 1);
        if ($sdn_next == 0) {
            if ($year == -1) {
                $sdn_next = _cal_julian_to_sdn(1, 1, 1);
            } else {
                $sdn_next = _cal_julian_to_sdn($year + 1, 1, 1);
            }
        }
    } elseif ($calendar == 2) {
        $sdn_start = _cal_jewish_to_sdn($year, $month, 1);
        if ($sdn_start == 0) { throw new Exception("cal_days_in_month: invalid date"); }
        $sdn_next = _cal_jewish_to_sdn($year, $month + 1, 1);
        if ($sdn_next == 0) {
            if ($year == -1) {
                $sdn_next = _cal_jewish_to_sdn(1, 1, 1);
            } else {
                $sdn_next = _cal_jewish_to_sdn($year + 1, 1, 1);
            }
        }
    } else {
        // French
        $sdn_start = _cal_french_to_sdn($year, $month, 1);
        if ($sdn_start == 0) { throw new Exception("cal_days_in_month: invalid date"); }
        $sdn_next = _cal_french_to_sdn($year, $month + 1, 1);
        if ($sdn_next == 0) {
            if ($year == -1) {
                $sdn_next = _cal_french_to_sdn(1, 1, 1);
            } else {
                $sdn_next = _cal_french_to_sdn($year + 1, 1, 1);
                if ($sdn_next == 0) {
                    $sdn_next = 2380953;
                }
            }
        }
    }

    return $sdn_next - $sdn_start;
}

// ════════════════════════════════════════════════════════════
// 公共 API：cal_from_jd
// ════════════════════════════════════════════════════════════

/**
 * cal_from_jd(int $jd, int $calendar): array
 *
 * 将 JD 转换为指定日历，返回关联数组:
 *   ["date" => "month/day/year", "month" => int, "day" => int, "year" => int,
 *    "dow" => int, "abbrevdayname" => string, "dayname" => string,
 *    "abbrevmonth" => string, "monthname" => string]
 */
function cal_from_jd(int $jd, int $calendar): array|Exception
{
    if ($calendar < 0 || $calendar >= 4) {
        throw new Exception("cal_from_jd: invalid calendar ID");
    }

    $r = ["month" => 0, "day" => 0, "year" => 0];
    $abbrevMonth = "";
    $monthName = "";

    if ($calendar == 0) {
        $r = _cal_sdn_to_gregorian($jd);
        if ($r["year"] == 0) { throw new Exception("cal_from_jd: JD out of range"); }
        $abbrevMonth = _cal_gregorian_month_short($r["month"]);
        $monthName = _cal_gregorian_month_long($r["month"]);
    } elseif ($calendar == 1) {
        $r = _cal_sdn_to_julian($jd);
        if ($r["year"] == 0) { throw new Exception("cal_from_jd: JD out of range"); }
        $abbrevMonth = _cal_gregorian_month_short($r["month"]);
        $monthName = _cal_gregorian_month_long($r["month"]);
    } elseif ($calendar == 2) {
        $r = _cal_sdn_to_jewish($jd);
        if ($r["year"] == 0) { throw new Exception("cal_from_jd: JD out of range"); }
        $abbrevMonth = _cal_jewish_month_name($r["month"], $r["year"]);
        $monthName = $abbrevMonth;
    } else {
        $r = _cal_sdn_to_french($jd);
        if ($r["year"] == 0) { throw new Exception("cal_from_jd: JD out of range"); }
        $abbrevMonth = _cal_french_month_name($r["month"]);
        $monthName = $abbrevMonth;
    }

    $dow = _cal_day_of_week($jd);

    $result = [];
    $result["date"] = $r["month"] . "/" . $r["day"] . "/" . $r["year"];
    $result["month"] = $r["month"];
    $result["day"] = $r["day"];
    $result["year"] = $r["year"];
    $result["dow"] = $dow;
    $result["abbrevdayname"] = _cal_day_name_short($dow);
    $result["dayname"] = _cal_day_name_long($dow);
    $result["abbrevmonth"] = $abbrevMonth;
    $result["monthname"] = $monthName;
    return $result;
}

// ════════════════════════════════════════════════════════════
// 公共 API：cal_to_jd
// ════════════════════════════════════════════════════════════

/**
 * cal_to_jd(int $calendar, int $month, int $day, int $year): int
 *
 * 日历转 JD。根据 $calendar 分发到对应的 xxxtojd 函数。
 */
function cal_to_jd(int $calendar, int $month, int $day, int $year): int|Exception
{
    if ($calendar < 0 || $calendar >= 4) {
        throw new Exception("cal_to_jd: invalid calendar ID");
    }
    $sdn = 0;
    if ($calendar == 0) {
        $sdn = _cal_gregorian_to_sdn($year, $month, $day);
    } elseif ($calendar == 1) {
        $sdn = _cal_julian_to_sdn($year, $month, $day);
    } elseif ($calendar == 2) {
        $sdn = _cal_jewish_to_sdn($year, $month, $day);
    } else {
        $sdn = _cal_french_to_sdn($year, $month, $day);
    }
    if ($sdn == 0) {
        throw new Exception("cal_to_jd: invalid date");
    }
    return $sdn;
}

// ════════════════════════════════════════════════════════════
// 公共 API：cal_info
// ════════════════════════════════════════════════════════════

// 单个日历的信息
function _cal_info_one(int $calendar): array
{
    $result = [];
    if ($calendar == 0) {
        $result["calname"] = "Gregorian";
        $result["calsymbol"] = "CAL_GREGORIAN";
        $result["maxdaysinmonth"] = 31;
        $result["nummonths"] = 12;
        $result["month_1"] = "January";
        $result["month_2"] = "February";
        $result["month_3"] = "March";
        $result["month_4"] = "April";
        $result["month_5"] = "May";
        $result["month_6"] = "June";
        $result["month_7"] = "July";
        $result["month_8"] = "August";
        $result["month_9"] = "September";
        $result["month_10"] = "October";
        $result["month_11"] = "November";
        $result["month_12"] = "December";
    } elseif ($calendar == 1) {
        $result["calname"] = "Julian";
        $result["calsymbol"] = "CAL_JULIAN";
        $result["maxdaysinmonth"] = 31;
        $result["nummonths"] = 12;
        $result["month_1"] = "January";
        $result["month_2"] = "February";
        $result["month_3"] = "March";
        $result["month_4"] = "April";
        $result["month_5"] = "May";
        $result["month_6"] = "June";
        $result["month_7"] = "July";
        $result["month_8"] = "August";
        $result["month_9"] = "September";
        $result["month_10"] = "October";
        $result["month_11"] = "November";
        $result["month_12"] = "December";
    } elseif ($calendar == 2) {
        $result["calname"] = "Jewish";
        $result["calsymbol"] = "CAL_JEWISH";
        $result["maxdaysinmonth"] = 30;
        $result["nummonths"] = 13;
        $result["month_1"] = "Tishri";
        $result["month_2"] = "Heshvan";
        $result["month_3"] = "Kislev";
        $result["month_4"] = "Tevet";
        $result["month_5"] = "Shevat";
        $result["month_6"] = "Adar I";
        $result["month_7"] = "Adar II";
        $result["month_8"] = "Nisan";
        $result["month_9"] = "Iyyar";
        $result["month_10"] = "Sivan";
        $result["month_11"] = "Tammuz";
        $result["month_12"] = "Av";
        $result["month_13"] = "Elul";
    } else {
        $result["calname"] = "French";
        $result["calsymbol"] = "CAL_FRENCH";
        $result["maxdaysinmonth"] = 30;
        $result["nummonths"] = 13;
        $result["month_1"] = "Vendemiaire";
        $result["month_2"] = "Brumaire";
        $result["month_3"] = "Frimaire";
        $result["month_4"] = "Nivose";
        $result["month_5"] = "Pluviose";
        $result["month_6"] = "Ventose";
        $result["month_7"] = "Germinal";
        $result["month_8"] = "Floreal";
        $result["month_9"] = "Prairial";
        $result["month_10"] = "Messidor";
        $result["month_11"] = "Thermidor";
        $result["month_12"] = "Fructidor";
        $result["month_13"] = "Extra";
    }
    return $result;
}

/**
 * cal_info(int $calendar = -1): array
 *
 * 返回日历元信息 (月份名、最大天数等)。
 * -1 返回所有日历信息（嵌套数组）。
 */
function cal_info(int $calendar = -1): array|Exception
{
    if ($calendar == -1) {
        $result = [];
        $result[0] = _cal_info_one(0);
        $result[1] = _cal_info_one(1);
        $result[2] = _cal_info_one(2);
        $result[3] = _cal_info_one(3);
        return $result;
    }
    if ($calendar < 0 || $calendar >= 4) {
        throw new Exception("cal_info: invalid calendar ID");
    }
    return _cal_info_one($calendar);
}

// ════════════════════════════════════════════════════════════
// 公共 API：easter_date / easter_days
// ════════════════════════════════════════════════════════════

// 内部：计算复活节距 3月21日 的天数
function _cal_easter_days(int $year, int $method): int|Exception
{
    if ($year <= 0) {
        throw new Exception("easter: year must be positive");
    }

    $golden = ($year % 19) + 1;

    $dom = 0;
    $pfm = 0;

    $useJulian = false;
    if (($year <= 1582 && $method != 2) ||
        ($year >= 1583 && $year <= 1752 && $method != 1 && $method != 2) ||
        $method == 3) {
        $useJulian = true;
    }

    if ($useJulian) {
        $dom = ($year + ($year / 4) + 5) % 7;
        if ($dom < 0) { $dom = $dom + 7; }
        $pfm = (3 - (11 * $golden) - 7) % 30;
        if ($pfm < 0) { $pfm = $pfm + 30; }
    } else {
        $dom = ($year + ($year / 4) - ($year / 100) + ($year / 400)) % 7;
        if ($dom < 0) { $dom = $dom + 7; }
        $solar = ($year - 1600) / 100 - ($year - 1600) / 400;
        $lunar = ((($year - 1400) / 100) * 8) / 25;
        $pfm = (3 - (11 * $golden) + $solar - $lunar) % 30;
        if ($pfm < 0) { $pfm = $pfm + 30; }
    }

    if ($pfm == 29 || ($pfm == 28 && $golden > 11)) {
        $pfm = $pfm - 1;
    }

    $tmp = (4 - $pfm - $dom) % 7;
    if ($tmp < 0) { $tmp = $tmp + 7; }

    return $pfm + $tmp + 1;
}

/**
 * easter_date(int $year, int $mode = 0): int
 *
 * 返回指定年复活节的 Unix 时间戳。
 * 算法: Meeus/Jones/Butcher Gregorian algorithm
 */
function easter_date(int $year, int $mode = 0): int|Exception
{
    if ($year < 1970) {
        throw new Exception("easter_date: year must be >= 1970");
    }

    $easter = _cal_easter_days($year, $mode);

    $month = 0;
    $day = 0;
    if ($easter < 11) {
        $month = 3;
        $day = $easter + 21;
    } else {
        $month = 4;
        $day = $easter - 10;
    }

    return mktime(0, 0, 0, $month, $day, $year);
}

/**
 * easter_days(int $year, int $mode = 0): int
 *
 * 返回复活节距 3月21日 的天数。
 */
function easter_days(int $year, int $mode = 0): int|Exception
{
    return _cal_easter_days($year, $mode);
}
