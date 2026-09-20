<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;
defined('MOODLE_INTERNAL') || die();

final class estimate {
    /** Read metadata only. Private submissions/reviews remain explicitly unresolved until collection. */
    public static function inventory(int $userid, array $selected = [], string $kind = 'learner'): array {
        global $CFG, $USER;
        if ($userid !== (int)$USER->id && !has_capability('local/tomb:manage', \context_system::instance())) {
            throw new \moodle_exception('unavailable', 'local_tomb');
        }
        require_capability('local/tomb:exportown', \context_system::instance(), $userid);
        $result = ['subjectid' => $userid, 'courses' => [], 'expected_pages' => 2,
            'known_shared_source_bytes' => 0, 'unresolved_activities' => 0, 'zip_created' => false];
        $hashes = [];
        $weight = 2;
        foreach (manager::courses($userid) as $course) {
            if ($selected && !in_array((int)$course->id, $selected, true)) {continue;}
            $context = \context_course::instance($course->id);
            $factor = 1;
            if ($kind === 'teacher') {
                require_capability('local/tomb:exportteacher', $context, $userid);
                $factor = max(1, count(get_enrolled_users($context, 'moodle/grade:view', 0, 'u.id')));
            }
            $modules = [];
            foreach (get_fast_modinfo($course, $userid)->get_cms() as $cm) {
                if (!$cm->uservisible || !empty($cm->deletioninprogress)) {continue;}
                $modules[$cm->modname] = ($modules[$cm->modname] ?? 0) + 1;
                $areas = ['intro'];
                if (in_array($cm->modname, ['resource', 'folder', 'page'], true)) {$areas[] = 'content';}
                else {$result['unresolved_activities']++;}
                foreach ($areas as $area) {
                    foreach (get_file_storage()->get_area_files(\context_module::instance($cm->id)->id,
                            'mod_' . $cm->modname, $area, 0, 'id', false) as $file) {
                        if (!isset($hashes[$file->get_contenthash()])) {
                            $hashes[$file->get_contenthash()] = true;
                            $result['known_shared_source_bytes'] += (int)$file->get_filesize();
                        }
                    }
                }
            }
            $pages = 2 + array_sum($modules);
            $result['expected_pages'] += $pages;
            $weight += $pages * $factor;
            $result['courses'][] = ['id' => (int)$course->id, 'name' => $course->fullname, 'visible_activities' => $modules];
        }
        $assets = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($CFG->dirroot . '/local/tomb/assets',
                \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {$assets += $file->getSize();}
        }
        // These are explicit planning allowances, not upper bounds on arbitrary user content.
        $result['new_material_budget_bytes'] = $assets + $weight * 262144;
        $result['privacy_index_budget_bytes'] = $weight * 262144;
        $result['temporary_zip_budget_bytes'] = $result['known_shared_source_bytes'] + $result['new_material_budget_bytes'];
        $result['completed_cache_budget_bytes'] = $result['temporary_zip_budget_bytes'];
        $result['additional_working_budget_bytes'] = 2 * $result['temporary_zip_budget_bytes'] + $result['privacy_index_budget_bytes'];
        $result['capacity_available_for_budget'] = config::capacity($result['additional_working_budget_bytes']);
        $result['is_upper_bound'] = false;
        $result['note'] = i18n::get('estimatenote');
        return $result;
    }

    public static function measured(int $id, string $phase, float $start, int $before): void {
        global $DB;
        $request = $DB->get_record('local_tomb_request', ['id' => $id]);
        if (!$request) {return;}
        $metrics = json_decode($request->metrics ?? '{}', true) ?: [];
        $metrics[$phase] = ['seconds' => round(microtime(true) - $start, 4),
            'memory_before_bytes' => $before, 'memory_after_bytes' => memory_get_usage(true),
            'process_peak_bytes' => memory_get_peak_usage(true)];
        $totals = $DB->get_records_sql('SELECT sourcetype, SUM(size) AS logicalbytes, SUM(compressed) AS storedbytes
            FROM {local_tomb_entry} WHERE requestid = ? GROUP BY sourcetype', [$id]);
        $metrics['materials'] = ['shared_logical_bytes' => (int)($totals['shared']->logicalbytes ?? 0),
            'inline_stored_bytes' => (int)($totals['inline']->storedbytes ?? 0), 'zip_bytes' => (int)$request->totalbytes];
        $metrics['materials']['privacy_index_bytes'] = (int)$DB->get_field_sql('SELECT COALESCE(SUM(' .
            $DB->sql_length('payload') . '),0) FROM {local_tomb_person} WHERE requestid = ?', [$id]);
        $DB->set_field('local_tomb_request', 'metrics', json_encode($metrics, JSON_THROW_ON_ERROR), ['id' => $id]);
    }
}
