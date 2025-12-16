<?php
if (!defined('ABSPATH')) {
    exit;
}

class TPMA_CR_DateTime {
    const WEEKDAYS = array('日', '一', '二', '三', '四', '五', '六');

    /**
     * Build range parts based on start datetime and duration.
     *
     * @param string $datetime
     * @param int    $duration_minutes
     * @return array|null ['date','weekday','start','end']
     */
    public static function build_range($datetime, $duration_minutes = 0) {
        if (empty($datetime)) {
            return null;
        }
        $start_ts = strtotime($datetime);
        if (!$start_ts) {
            return null;
        }

        $has_duration = is_numeric($duration_minutes) && intval($duration_minutes) > 0;
        $end_ts = $has_duration ? $start_ts + (intval($duration_minutes) * 60) : 0;

        return array(
            'date'    => date('Y/m/d', $start_ts),
            'weekday' => self::WEEKDAYS[(int) date('w', $start_ts)] ?? '',
            'start'   => date('H:i', $start_ts),
            'end'     => $has_duration ? date('H:i', $end_ts) : '',
        );
    }

    /**
     * Format datetime range with the standard pattern:
     * YYYY/MM/DD（Week）HH:MM~HH:MM
     * When duration is empty, end time is omitted.
     *
     * @param string  $datetime
     * @param int     $duration_minutes
     * @param boolean $multi_line Use line break between date and time.
     * @param string  $separator  Custom separator if multi_line is true.
     * @return string
     */
    public static function format_range($datetime, $duration_minutes = 0, $multi_line = false, $separator = null) {
        $info = self::build_range($datetime, $duration_minutes);
        if (!$info) {
            return '';
        }
        $sep = $multi_line ? ($separator !== null ? $separator : "\n") : ' ';
        $prefix = $info['weekday'] !== '' ? sprintf('%s（%s）', $info['date'], $info['weekday']) : $info['date'];
        $time_part = $info['end'] !== '' ? $info['start'] . '~' . $info['end'] : $info['start'];
        return $time_part ? $prefix . $sep . $time_part : $prefix;
    }

    /**
     * Format datetime without duration.
     *
     * @param string  $datetime
     * @param boolean $multi_line
     * @param string  $separator
     * @return string
     */
    public static function format_single($datetime, $multi_line = false, $separator = null) {
        return self::format_range($datetime, 0, $multi_line, $separator);
    }
}
