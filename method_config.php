<?php

const PMU_EXPANDED_Q5_METHOD_START = '20260425';
const PMU_EXPANDED_Q5_SOURCE_MODE = 'q5_cote_ge2_no_upper_limit_20260425';

function pmu_date_to_ymd(string $date): ?string
{
    $date = trim($date);
    if (preg_match('/^\d{8}$/', $date)) {
        return substr($date, 4, 4) . substr($date, 2, 2) . substr($date, 0, 2);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return str_replace('-', '', $date);
    }
    return null;
}

function pmu_uses_expanded_q5_method(string $date): bool
{
    $ymd = pmu_date_to_ymd($date);
    return $ymd !== null && $ymd >= PMU_EXPANDED_Q5_METHOD_START;
}

function pmu_profile_rank_from_cote(?float $cote, bool $expandedMethod = false): ?int
{
    if ($cote === null) {
        return null;
    }
    if ($cote >= 5.0 && $cote <= 8.0) {
        return 1;
    }
    if ($cote >= 3.0 && $cote < 5.0) {
        return 2;
    }
    if ($cote >= 2.0 && $cote < 3.0) {
        return 3;
    }
    return ($expandedMethod && $cote >= 2.0) ? 4 : null;
}
