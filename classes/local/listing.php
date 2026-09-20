<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;
defined('MOODLE_INTERNAL') || die();

/** The same authorised selection drives counts, pages and CSV exports. */
final class listing {
    private string $where;
    private array $params;

    public static function parameters(): array {
        return ['scope' => optional_param('scope', config::mode() === 'rehearsal' ? 'rehearsal' : 'production', PARAM_ALPHA),
            'coursefilter' => max(0, optional_param('coursefilter', 0, PARAM_INT)),
            'statusfilter' => optional_param('statusfilter', '', PARAM_ALPHA),
            'receiptfilter' => optional_param('receiptfilter', optional_param('unreceived', 0, PARAM_BOOL) ? 'unreceived' : '', PARAM_ALPHA)];
    }

    public function __construct(string $audience, array $filters, array $eligible = []) {
        global $DB, $USER;
        $where = ['isrehearsal = :rehearsal'];
        $params = ['rehearsal' => ($filters['scope'] ?? '') === 'rehearsal' ? 1 : 0];
        if ($audience === 'admin') {
            require_capability('local/tomb:manage', \context_system::instance());
        } else if ($audience === 'own') {
            require_capability('local/tomb:exportown', \context_system::instance());
            $where[] = 'subjectid = :owner';
            $params['owner'] = $USER->id;
        } else if ($audience === 'delegated') {
            $courseid = (int)($filters['coursefilter'] ?? 0);
            require_capability('local/tomb:delegate', \context_course::instance($courseid));
            $where[] = 'requesterid = :requester AND subjectid <> :requesterowner';
            $params += ['requester' => $USER->id, 'requesterowner' => $USER->id];
            if ($eligible) {
                [$in, $ids] = $DB->get_in_or_equal(array_map('intval', $eligible), SQL_PARAMS_NAMED, 'eligible');
                $where[] = 'subjectid ' . $in;
                $params += $ids;
            } else {
                $where[] = '1 = 0';
            }
        } else {
            throw new \invalid_parameter_exception('Unknown listing audience');
        }
        $course = (int)($filters['coursefilter'] ?? 0);
        if ($course > 0) {
            // Course IDs are canonical integer JSON written by manager::request; match whole tokens.
            $patterns = ['[' . $course . ']', '[' . $course . ',%', '%,' . $course . ',%', '%,' . $course . ']'];
            $parts = [];
            foreach ($patterns as $i => $pattern) {
                $parts[] = $DB->sql_like('courses', ':course' . $i);
                $params['course' . $i] = $pattern;
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        $status = $filters['statusfilter'] ?? '';
        if (in_array($status, ['queued', 'running', 'ready', 'failed', 'blocked'], true)) {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        $receipt = match ($filters['receiptfilter'] ?? '') {
            'unreceived' => 'received = 0 AND timepurged = 0',
            'notified' => 'notified > 0 AND downloadcount = 0 AND received = 0 AND timepurged = 0',
            'started' => 'downloadcount > 0 AND received = 0 AND timepurged = 0',
            'confirmed' => 'received > 0',
            default => '',
        };
        if ($receipt) {$where[] = '(' . $receipt . ')';}
        $this->where = implode(' AND ', $where);
        $this->params = $params;
    }

    public function page(int $page, int $perpage = 25): array {
        global $DB;
        $perpage = max(1, min(100, $perpage));
        $count = $DB->count_records_select('local_tomb_request', $this->where, $this->params);
        $page = max(0, min($page, (int)max(0, ceil($count / $perpage) - 1)));
        return ['total' => $count, 'page' => $page, 'perpage' => $perpage,
            'records' => $DB->get_records_select('local_tomb_request', $this->where, $this->params, 'id DESC', '*', $page * $perpage, $perpage)];
    }

    public function recordset(): \moodle_recordset {
        global $DB;
        return $DB->get_recordset_select('local_tomb_request', $this->where, $this->params, 'id DESC');
    }

    public static function navigation(array $page, \moodle_url $url): string {
        global $OUTPUT;
        $data = (object)['from' => $page['total'] ? $page['page'] * $page['perpage'] + 1 : 0,
            'to' => min($page['total'], ($page['page'] + 1) * $page['perpage']), 'total' => $page['total']];
        return '<p class="tomb-muted">' . s(i18n::get('listingrange', $data)) . '</p>' .
            $OUTPUT->paging_bar($page['total'], $page['page'], $page['perpage'], $url);
    }

    public static function form(array $filters, array $courses, bool $scope = false, array $hidden = []): string {
        $html = '<form method="get" class="tomb-filters">';
        foreach ($hidden as $key => $value) {
            $html .= '<input type="hidden" name="' . s($key) . '" value="' . s($value) . '">';
        }
        $fields = [];
        if ($scope) {$fields['scope'] = ['production' => i18n::get('scopeproduction'), 'rehearsal' => i18n::get('scoperehearsal')];}
        if ($courses) {$fields['coursefilter'] = [0 => i18n::get('allcourses')] + array_map(fn($c) => $c->fullname, $courses);}
        $fields['statusfilter'] = ['' => i18n::get('allstatuses')];
        foreach (['queued', 'running', 'ready', 'failed', 'blocked'] as $status) {
            $fields['statusfilter'][$status] = i18n::get('collection_' . $status);
        }
        $fields['receiptfilter'] = ['' => i18n::get('allreceipts')];
        foreach (['unreceived', 'notified', 'started', 'confirmed'] as $receipt) {
            $fields['receiptfilter'][$receipt] = i18n::get('receipt_' . $receipt);
        }
        foreach ($fields as $name => $options) {
            $html .= '<label>' . s(i18n::get('filter_' . $name)) . '<select name="' . $name . '" class="custom-select">';
            foreach ($options as $value => $label) {
                $html .= '<option value="' . s($value) . '"' . ((string)($filters[$name] ?? '') === (string)$value ? ' selected' : '') . '>' . s($label) . '</option>';
            }
            $html .= '</select></label> ';
        }
        return $html . '<button class="tomb-button tomb-secondary" type="submit">' . s(i18n::get('applyfilters')) . '</button></form>';
    }
}
