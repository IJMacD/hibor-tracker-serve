<?php

require_once "vendor/autoload.php";

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

if (isset($argc) && $argc > 1) {
    $spec = $argv[1];
} else if (isset($_GET['spec'])) {
    $spec = $_GET['spec'];
} else {
    $spec = date("Y-m-d");
}

$dates = getDates($spec);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$db = new PDO("mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4", $_ENV['DB_USER'], $_ENV['DB_PASS'], $options);

$params = array_map(function () {
    return "?";
}, $dates);

$parsed = parseISO8601($spec);
$order = $parsed['repeatCount'] < 0 ? "DESC" : "ASC";

$stmt = $db->prepare("SELECT `date`, `one_month` FROM hibor_track WHERE `date` IN (" . implode(",", $params) . ") ORDER BY `date` " . $order);

$stmt->execute($dates);

header("Content-Type: application/json");
echo json_encode($stmt->fetchAll(), JSON_NUMERIC_CHECK);

function getDates($formatSpec)
{
    $parsed = parseISO8601($formatSpec);
    $current = $parsed['start'];

    $out = [
        $current->format("Y-m-d"),
    ];

    if ($parsed['repeatCount'] > 0) {
        for ($i = 0; $i < $parsed['repeatCount'] && $i < 1000; $i++) {
            $current = $current->add($parsed['duration']);
            $out[] = $current->format("Y-m-d");
        }
    } else if ($parsed['repeatCount'] < 0) {
        for ($i = 0; $i > $parsed['repeatCount'] && $i > -1000; $i--) {
            $current = $current->sub($parsed['duration']);
            $out[] = $current->format("Y-m-d");
        }
    }

    return $out;
}

function parseISO8601($string)
{
    $repeatCount = 0;
    $start = null;
    $end = null;
    $duration = null;

    $parts = preg_split("/\/|--/", $string);

    if (count($parts) === 3) {
        if ($parts[0][0] !== "R") {
            throw new RuntimeException("Invalid format: Expecting 'R'");
        }

        // Parse repetition
        if (strlen($parts[0]) > 1) {
            $repeatCount = (int)substr($parts[0], 1);
        } else {
            $repeatCount = PHP_FLOAT_MAX;
        }

        // parse start
        $start = new DateTimeImmutable($parts[1]);

        if ($parts[2][0] === "P") {
            // parse period
            $duration = new DateInterval($parts[2]);
            $end = $start->add($duration);
        } else {
            // parse end
            $end = new DateTimeImmutable($parts[2]);
            $duration = $start->diff($end);
        }
    } else if (count($parts) === 2) {
        // parse start
        $start = new DateTimeImmutable($parts[0]);

        if ($parts[1][0] === "P") {
            //parse period
            $duration = new DateInterval($parts[1]);
            $end = $start->add($duration);
        } else {
            // parse end
            $end = new DateTimeImmutable($parts[1]);
            $duration = $start->diff($end);
        }
    } else {
        // parse start
        $start = new DateTimeImmutable($parts[0]);
    }

    return [
        "repeatCount" => $repeatCount,
        "start" => $start,
        "end" => $end,
        "duration" => $duration,
    ];
}

function parseISO8601DateTime($string)
{
    $dt = explode("T", $string);

    $d = explode("-", $dt[0]);

    $year = (int)$d[0];
    $month = (int)$d[1];
    $day = (int)$d[2];
    $hour = 0;
    $minute = 0;
    $second = 0;
    $zoneHour = 0;
    $zoneMinute = 0;

    if (isset($dt[1])) {
        $dt1 = preg_replace("/Z$/", "", $dt[1]);
        $ttz = preg_split("/[+−-]/", $dt1);

        $t = explode(":", $ttz[0]);

        if (isset($t[0])) $hour = (int)$t[0];
        if (isset($t[1])) $minute = (int)$t[1];
        if (isset($t[2])) $second = (float)$t[2];

        if (isset($ttz[1])) {
            $z = explode(":", $ttz[1]);

            if (isset($z[0])) $zoneHour = (int)$z[0];
            if (isset($z[1])) $zoneMinute = (int)$z[1];

            if (preg_match("/[−-]/", $dt1)) {
                $zoneHour *= -1;
            }
        }
    }

    return [
        "year" => $year,
        "month" => $month,
        "day" => $day,
        "hour" => $hour,
        "minute" => $minute,
        "second" => $second,
        "zoneHour" => $zoneHour,
        "zoneMinute" => $zoneMinute,
    ];
}

function parseISO8601Period($string)
{
    if ($string[0] !== "P") {
        throw new RuntimeException("Invalid format: Expecting 'P'");
    }

    $year = 0;
    $month = 0;
    $week = 0;
    $day = 0;
    $hour = 0;
    $minute = 0;
    $second = 0;

    $dt = explode("T", $string);

    preg_match_all("/(\d+)(\w)/", $dt[0], $d_matches, PREG_SET_ORDER);

    foreach ($d_matches as $match) {
        $value = (int) $match[1];

        if ($match[2] === "Y") $year = $value;
        else if ($match[2] === "M") $month = $value;
        else if ($match[2] === "W") $week = $value;
        else if ($match[2] === "D") $day = $value;
    }

    if (isset($dt[1])) {
        preg_match_all("/(\d+)(\w)/", $dt[1], $t_matches, PREG_SET_ORDER);

        foreach ($t_matches as $match) {
            $value = (int) $match[1];

            if ($match[2] === "H") $hour = $value;
            else if ($match[2] === "M") $minute = $value;
            else if ($match[2] === "S") $second = $value;
        }
    }

    return [
        "year" => $year,
        "month" => $month,
        "week" => $week,
        "day" => $day,
        "hour" => $hour,
        "minute" => $minute,
        "second" => $second,
    ];
}

function dateAddPeriod($start, $period)
{
    $dt = new DateTime($start);
    $p = new DateInterval($period);
    $e = $dt->add($p);

    return [
        "year" => $e->format("Y"),
        "month" => $e->format("m"),
        "day" => $e->format("d"),
        "hour" => $e->format("H"),
        "minute" => $e->format("i"),
        "second" => $e->format("s"),
        "zoneHour" => substr($e->format("O"), 0, 3),
        "zoneMinute" => substr($e->format("O"), 3),
    ];
}