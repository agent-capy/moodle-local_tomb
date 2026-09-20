<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;
defined('MOODLE_INTERNAL') || die();

final class collector {
    private object $request;
    private storage $storage;
    private array $courses = [];
    private array $map = [];
    private array $sourceids = [];
    private array $aliases = [];
    private int $courseid = 0;
    private int $cmid = 0;
    private array $documents = [];
    private array $sourcechecks = [];
    private array $filelookup = [];
    private array $excludedcourses = [];

    public function __construct(object $request) {
        $this->request = $request;
    }

    private function e(string $text): string {
        return paths::escape($text);
    }

    private function omit(string $reason, string $detail): void {
        manager::omission((int)$this->request->id, $this->courseid, $this->cmid, $reason, $detail);
    }

    public function generate(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        manager::progress($this->request->id, 'collecting');
        $available = manager::courses((int)$this->request->subjectid);
        foreach (json_decode($this->request->courses, true, 512, JSON_THROW_ON_ERROR) as $courseid) {
            if (!$DB->record_exists('course', ['id' => $courseid])) {
                continue;
            }
            $this->courseid = (int)$courseid;
            if (!isset($available[$courseid])) {
                $this->excludedcourses[] = $courseid;
                continue;
            }
            $course = get_course($courseid);
            if ($this->request->kind === 'teacher') {
                require_capability('local/tomb:exportteacher', \context_course::instance($courseid));
                if ($this->request->policy === 'full') {
                    require_capability('local/tomb:exportothersdata', \context_course::instance($courseid));
                }
            }
            $info = get_fast_modinfo($course, $this->request->subjectid);
            $models = [];
            foreach ($info->get_cms() as $cm) {
                if (!$cm->uservisible || !empty($cm->deletioninprogress)) {
                    continue;
                }
                $models[$cm->id] = ['cm' => $cm, 'instance' => $DB->get_record($cm->modname, ['id' => $cm->instance]),
                    'path' => 'courses/c' . $courseid . '/m' . $cm->id . '/index.html'];
            }
            $this->courses[$courseid] = ['course' => $course, 'models' => $models,
                'sections' => $info->get_section_info_all()];
        }
        // Re-render if a source disappears while HTML is being finalised. Bound retries on a changing site.
        for ($pass = 0; $pass < 3; $pass++) {
            storage::clear($this->request, true);
            foreach ($this->excludedcourses as $courseid) {
                manager::omission($this->request->id, (int)$courseid, 0, 'policy_excluded',
                    i18n::get('coursenolongeravailable'));
            }
            $this->storage = new storage($this->request);
            $this->documents = [];
            $this->sourceids = [];
            $this->sourcechecks = [];
            $this->filelookup = [];
            $this->remove_deleted_models();
            $this->map = [];
            foreach ($this->courses as $id => $data) {
                $this->map['course:' . $id] = 'courses/c' . $id . '/index.html';
                foreach ($data['models'] as $cmid => $model) {
                    $this->map['cm:' . $cmid] = $model['path'];
                    $component = 'mod_' . $model['cm']->modname;
                    $contextid = \context_module::instance($cmid)->id;
                    $areas = ['intro'];
                    if (in_array($model['cm']->modname, ['resource', 'folder', 'page'], true)) {
                        $areas[] = 'content';
                    }
                    foreach ($areas as $area) {
                        foreach ($this->files($contextid, $component, $area, 0) as $file) {
                            $key = $contextid . '/' . $component . '/' . $area . '/0' . $file->get_filepath() . $file->get_filename();
                            $this->filelookup[$key] = $file;
                        }
                    }
                }
            }
            manager::progress($this->request->id, 'rendering');
            foreach ($this->courses as $id => $data) {
                $this->courseid = (int)$id;
                $coursepath = 'courses/c' . $id . '/index.html';
                $navigation = '';
                $sectionnumber = null;
                foreach ($data['models'] as $cmid => $model) {
                    $this->cmid = (int)$cmid;
                    $cm = $model['cm'];
                    if ($sectionnumber !== $cm->sectionnum) {
                        $sectionnumber = $cm->sectionnum;
                        $section = $data['sections'][$sectionnumber] ?? null;
                        $navigation .= '<h3>' . $this->e($section && $section->name !== null ? $section->name :
                            ($sectionnumber ? i18n::get('text_section_8510a9') . $sectionnumber : i18n::get('text_introduction_46a7f4'))) . '</h3>';
                        if ($section && $section->summary) {
                            $navigation .= $this->content($section->summary, (int)$section->summaryformat, $coursepath,
                                \context_course::instance($id), 'course', 'section', (int)$section->id);
                        }
                    }
                    manager::progress($this->request->id, 'rendering', $data['course']->fullname . ' / ' . $cm->name);
                    try {
                        $body = $this->activity($data['course'], $model);
                    } catch (\Throwable $e) {
                        if ($e->getCode() === 1002) {
                            throw $e;
                        }
                        audit::add('render_failed', $this->request->id, ['cmid' => $cmid,
                            'exception' => get_class($e), 'message' => substr($e->getMessage(), 0, 500)], 0);
                        $this->omit('render_error', $cm->name . ': ' . get_class($e));
                        $body = i18n::get('text_this_activity_could_not_be_7cf6c8');
                    }
                    $this->documents[$model['path']] = ['title' => $cm->name, 'body' => $body,
                        'eyebrow' => $cm->modname, 'courseid' => $id, 'cmid' => $cmid];
                    $navigation .= '<div class="activity"><span class="activity-icon">' . $this->e($cm->modname) .
                        '</span><div><a href="' . paths::relative($coursepath, $model['path']) . '">' .
                        $this->e($cm->name) . '</a><br><small>' . $this->e($data['course']->shortname) . '</small></div></div>';
                }
                $this->cmid = 0;
                $gradepath = 'courses/c' . $id . '/grades.html';
                $this->documents[$gradepath] = ['title' => i18n::get('text_grades_and_feedback_ea1e88'),
                    'body' => $this->grades($data['course'], $gradepath), 'eyebrow' => $data['course']->fullname,
                    'courseid' => $id, 'cmid' => 0];
                $this->documents[$coursepath] = ['title' => $data['course']->fullname,
                    'body' => i18n::get('text_revisit_your_materials_and_learning_a3d0fa') .
                        i18n::get('text_view_grades_and_feedback_b5b787') .
                        $navigation . '</div>', 'eyebrow' => 'COURSE ARCHIVE', 'courseid' => $id, 'cmid' => 0];
            }
            $changed = $this->remove_deleted_models();
            foreach ($this->sourceids as $fileid => $unused) {
                if (!$DB->record_exists('files', ['id' => $fileid])) {
                    $changed = true;
                }
            }
            foreach ($this->sourcechecks as [$table, $conditions]) {
                if (!$DB->record_exists($table, $conditions)) {
                    $changed = true;
                }
            }
            if ($changed) {
                continue;
            }
            $this->request->timecollected = time();
            $this->request->timefinished = time();
            $this->finish();
            return;
        }
        throw new \RuntimeException('Sources kept changing while the archive was finalised');
    }

    private function remove_deleted_models(): bool {
        global $DB;
        $changed = false;
        foreach ($this->courses as $id => &$data) {
            if (!$DB->record_exists('course', ['id' => $id])) {
                unset($this->courses[$id]);
                $changed = true;
                continue;
            }
            foreach ($data['models'] as $cmid => $model) {
                if (!$model['instance'] || !$DB->record_exists('course_modules', ['id' => $cmid]) ||
                        !$DB->record_exists($model['cm']->modname, ['id' => $model['cm']->instance])) {
                    unset($data['models'][$cmid]);
                    $changed = true;
                }
            }
        }
        unset($data);
        return $changed;
    }

    private function files(int $contextid, string $component, string $area, int $itemid): array {
        return get_file_storage()->get_area_files($contextid, $component, $area, $itemid, 'filepath, filename', false);
    }

    private function file_path(\stored_file $file, string $from): ?string {
        global $DB;
        if (!$DB->record_exists('files', ['id' => $file->get_id()])) {
            return null;
        }
        $this->sourceids[$file->get_id()] = true;
        try {
            return paths::relative($from, $this->storage->add_file($file, $this->courseid, $this->cmid));
        } catch (\Throwable $e) {
            if ($e->getCode() === 1002) {
                throw $e;
            }
            $this->omit('render_error', i18n::get('filedetail') . $file->get_filename() . ' (' . get_class($e) . ')');
            return null;
        }
    }

    private function attachments(array $files, string $from): string {
        $body = '';
        foreach ($files as $file) {
            $path = $this->file_path($file, $from);
            if ($path !== null) {
                $body .= '<a class="file-link" href="' . $path . '">↗ ' . $this->e($file->get_filename()) .
                    ' <span class="meta">' . $this->e(display_size($file->get_filesize())) . '</span></a>';
            }
        }
        return $body;
    }

    private function content(string $text, int $format, string $from, \context $context,
            string $component, string $area, int $itemid = 0): string {
        global $CFG;
        $files = $this->files($context->id, $component, $area, $itemid);
        $byname = [];
        foreach ($files as $file) {
            $byname[$file->get_filepath() . $file->get_filename()] = $file;
        }
        $formatted = format_text($text, $format, ['context' => $context, 'filter' => false, 'para' => false,
            'overflowdiv' => false, 'noclean' => false]);
        return html::rewrite($formatted, function(string $url) use ($byname, $from, $context, $component, $area, $itemid, $CFG) {
            $decoded = rawurldecode($url);
            if (str_starts_with($decoded, '@@PLUGINFILE@@')) {
                $name = substr($decoded, strlen('@@PLUGINFILE@@'));
                $name = explode('?', $name)[0];
                return isset($byname[$name]) ? $this->file_path($byname[$name], $from) : null;
            }
            $parts = parse_url($url);
            if (!$parts) {
                return null;
            }
            $sitehost = parse_url($CFG->wwwroot, PHP_URL_HOST);
            $internal = empty($parts['host']) || $parts['host'] === $sitehost;
            $path = rawurldecode($parts['path'] ?? '');
            if ($internal && str_contains($path, '/pluginfile.php/')) {
                $prefix = '/pluginfile.php/' . $context->id . '/' . $component . '/' . $area . '/' . $itemid;
                $start = strpos($path, $prefix . '/');
                if ($start !== false) {
                    $name = substr($path, $start + strlen($prefix));
                    return isset($byname[$name]) ? $this->file_path($byname[$name], $from) : null;
                }
                $suffix = substr($path, strpos($path, '/pluginfile.php/') + strlen('/pluginfile.php/'));
                $parts = explode('/', $suffix);
                $withoutitem = count($parts) > 3 ? implode('/', array_slice($parts, 0, 3)) . '/0/' .
                    implode('/', array_slice($parts, 3)) : '';
                $file = $this->filelookup[$suffix] ?? $this->filelookup[$withoutitem] ?? null;
                if ($file) {
                    return $this->file_path($file, $from);
                }
                return null;
            }
            parse_str($parts['query'] ?? '', $query);
            if ($internal && isset($query['id']) && preg_match('~/mod/[a-z0-9_]+/view\.php$~', $path)) {
                $target = $this->map['cm:' . (int)$query['id']] ?? null;
                return $target ? paths::relative($from, $target) : null;
            }
            if ($internal && isset($query['id']) && str_ends_with($path, '/course/view.php')) {
                $target = $this->map['course:' . (int)$query['id']] ?? null;
                return $target ? paths::relative($from, $target) : null;
            }
            if (!$internal && in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
                $this->omit('external_resource', i18n::get('externalurl') . $parts['host']);
            }
            return null;
        }, function(string $reason) {
            $this->omit($reason, i18n::get('embedunavailable'));
        });
    }

    private function activity(object $course, array $model): string {
        global $DB;
        $cm = $model['cm'];
        $instance = $model['instance'];
        $context = \context_module::instance($cm->id);
        $path = $model['path'];
        $body = '<div class="content">';
        if (!empty($instance->intro)) {
            $body .= $this->content($instance->intro, (int)$instance->introformat, $path, $context,
                'mod_' . $cm->modname, 'intro');
        }
        switch ($cm->modname) {
            case 'resource':
            case 'folder':
                $body .= $this->attachments($this->files($context->id, 'mod_' . $cm->modname, 'content', 0), $path);
                break;
            case 'page':
                $body .= $this->content($instance->content, (int)$instance->contentformat, $path, $context, 'mod_page', 'content');
                break;
            case 'label':
                break;
            case 'assign':
                $body .= $this->assignment($course, $model, $context);
                break;
            case 'forum':
                $body .= $this->forum($model, $context);
                break;
            case 'quiz':
                $body .= $this->quiz($course, $model, $context);
                break;
            default:
                $this->omit('unsupported_module', $cm->name . ' (' . $cm->modname . ')');
                $body .= i18n::get('text_this_activity_is_not_supported_97729d');
        }
        return $body . '</div>';
    }

    private function author(int $userid): string {
        personal_data::record($this->request, $userid, 'identity', ['type' => 'represented_person']);
        if ($userid === (int)$this->request->subjectid) {
            return i18n::get('text_you_91cb52');
        }
        if ($this->request->policy === 'full') {
            return fullname(\core_user::get_user($userid));
        }
        // A stable mapping inside one archive; no user IDs or mapping table in the export.
        if (!isset($this->aliases[$userid])) {
            $this->aliases[$userid] = i18n::get('text_participant_011ead') . str_pad((string)(count($this->aliases) + 1), 3, '0', STR_PAD_LEFT);
        }
        return $this->aliases[$userid];
    }

    private function forum(array $model, \context_module $context): string {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . '/mod/forum/lib.php');
        $forum = $model['instance'];
        $cm = $model['cm'];
        $path = $model['path'];
        $body = i18n::get($this->request->policy === 'full' ? 'forumnamed' : 'text_conversations_these_posts_were_visible_ad3d39');
        $discussions = $DB->get_records('forum_discussions', ['forum' => $forum->id], 'id ASC');
        $visible = 0;
        foreach ($discussions as $discussion) {
            if (!forum_user_can_see_discussion($forum, $discussion, $context, $USER)) {
                continue;
            }
            $posts = $DB->get_records('forum_posts', ['discussion' => $discussion->id], 'created ASC, id ASC');
            $thread = '';
            foreach ($posts as $post) {
                if (!forum_user_can_see_post($forum, $discussion, $post, $USER, $cm)) {
                    continue;
                }
                $visible++;
                $this->sourcechecks[] = ['forum_posts', ['id' => $post->id, 'deleted' => 0]];
                $this->sourcechecks[] = ['forum_discussions', ['id' => $discussion->id]];
                $fragment = '<section class="panel" id="post-' . $post->id . '"><span class="tag">' .
                    $this->e($this->author((int)$post->userid)) . '</span><span class="meta">' .
                    $this->e(userdate($post->created)) . '</span><h3>' . $this->e($post->subject) . '</h3>' .
                    $this->content($post->message, (int)$post->messageformat, $path, $context, 'mod_forum', 'post', (int)$post->id) .
                    $this->attachments($this->files($context->id, 'mod_forum', 'attachment', (int)$post->id), $path) . '</section>';
                $thread .= $fragment;
                personal_data::fragment($this->request, (int)$post->userid, 'forum:' . $post->id, $path, $fragment);
            }
            if ($thread !== '') {
                $body .= '<h3>' . $this->e($discussion->name) . '</h3>' . $thread;
            }
        }
        return $body . (!$visible ? i18n::get('text_there_are_no_posts_to_91ec9e') : '');
    }

    private function quiz(object $course, array $model, \context_module $context, ?int $foruser = null): string {
        global $DB, $CFG, $PAGE;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        if ($this->request->kind === 'teacher' && $foruser === null) {
            if (!has_capability('mod/quiz:viewreports', $context)) {
                $this->omit('policy_excluded', i18n::get('text_quiz_report_access_is_not_ef63ad'));
                return '';
            }
            $body = '';
            foreach ($this->participants($course, $model['cm'], 'mod/quiz:attempt') as $user) {
                $body .= '<h2>' . $this->e($this->author((int)$user->id)) . '</h2>' .
                    $this->quiz($course, $model, $context, (int)$user->id);
            }
            return $body;
        }
        $attempts = $DB->get_records('quiz_attempts', ['quiz' => $model['instance']->id,
            'userid' => $foruser ?? $this->request->subjectid, 'preview' => 0], 'attempt ASC');
        $body = i18n::get('text_quiz_attempts_the_questions_and_93046c');
        $previouspage = $PAGE;
        try {
            $PAGE = new \moodle_page();
            $PAGE->set_course($course);
            $PAGE->set_context($context);
            $PAGE->set_url('/mod/quiz/view.php', ['id' => $model['cm']->id]);
            $renderer = $PAGE->get_renderer('mod_quiz');
            foreach ($attempts as $attempt) {
                if ($attempt->state !== \mod_quiz\quiz_attempt::FINISHED) {
                    $this->omit('unfinished_attempt', i18n::get('text_an_unfinished_attempt_was_excluded_75fdbd') . $attempt->attempt . i18n::get('text_fragment_fa354c'));
                    continue;
                }
                $object = \mod_quiz\quiz_attempt::create($attempt->id);
                $this->sourcechecks[] = ['quiz_attempts', ['id' => $attempt->id]];
                $object->check_review_capability();
                $options = $object->get_display_options(true);
                if (!$options->attempt || ($foruser === null ? !$object->is_own_attempt() : !$object->is_review_allowed())) {
                    $this->omit('review_restricted', i18n::get('text_review_is_not_available_for_0b7f01') . $attempt->attempt);
                    continue;
                }
                // History/edit links can contain identities and online-only controls.
                $options->history = \question_display_options::HIDDEN;
                $options->flags = \question_display_options::HIDDEN;
                $options->questionreviewlink = null;
                $options->manualcommentlink = null;
                $options->readonly = true;
                $body .= i18n::get('text_attempt_eb4bf5') . (int)$attempt->attempt . i18n::get('text_completed_3106fe') .
                    $this->e(userdate($attempt->timefinish)) . '</p>';
                foreach ($object->get_slots() as $slot) {
                    $question = $object->get_question_attempt($slot)->get_question();
                    $type = $question->qtype->name();
                    if (!in_array($type, ['multichoice', 'truefalse', 'shortanswer', 'numerical', 'match', 'description'], true)) {
                        $this->omit('unsupported_question_type', i18n::get('text_question_type_310def') . $type . i18n::get('text_is_not_supported_by_this_3b4899'));
                        $body .= i18n::get('text_this_question_type_was_excluded_699392');
                        continue;
                    }
                    try {
                        $fragment = $object->render_question($slot, true, $renderer);
                    } catch (\Throwable $e) {
                        $this->omit('render_error', i18n::get('text_quiz_question_2c2dc7') . $slot . ' (' . $type . i18n::get('text_could_not_be_rendered_b5476b'));
                        audit::add('question_render_failed', $this->request->id, ['cmid' => $model['cm']->id,
                            'exception' => get_class($e)], 0);
                        continue;
                    }
                    $rendered = html::rewrite($fragment,
                        function(string $url) use ($object, $slot, $question, $context, $model, $CFG) {
                            $parts = parse_url($url);
                            if (!$parts || (!empty($parts['host']) && $parts['host'] !== parse_url($CFG->wwwroot, PHP_URL_HOST))) {
                                return null;
                            }
                            $path = rawurldecode($parts['path'] ?? '');
                            if (!preg_match('~/pluginfile.php/(\d+)/([^/]+)/([^/]+)/(\d+)/(\d+)/(.*)$~', $path, $match)) {
                                return null;
                            }
                            [$all, $ctx, $component, $area, $usage, $fileslot, $rest] = $match;
                            if ((int)$usage !== (int)$object->get_attempt()->uniqueid || (int)$fileslot !== (int)$slot ||
                                    !in_array((int)$ctx, [(int)$context->id, (int)$question->contextid], true)) {
                                return null;
                            }
                            $args = explode('/', $rest);
                            if (!$object->check_file_access($slot, true, (int)$ctx, $component, $area, $args, false)) {
                                return null;
                            }
                            $itemid = (int)array_shift($args);
                            $filename = array_pop($args);
                            $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
                            $file = get_file_storage()->get_file((int)$ctx, $component, $area, $itemid, $filepath, $filename);
                            return $file ? $this->file_path($file, $model['path']) : null;
                        }, function(string $reason) {
                            $this->omit($reason, i18n::get('text_a_quiz_embed_could_not_c74db6'));
                        });
                    $body .= '<section class="panel quiz-question">' . $rendered . '</section>';
                    if ($options->manualcomment == \question_display_options::VISIBLE) {
                        personal_data::question_comment($this->request, $object->get_question_attempt($slot), $model['path'], $rendered);
                    }
                }
                if ($options->overallfeedback) {
                    $grade = quiz_rescale_grade($attempt->sumgrades, $model['instance'], false);
                    $feedback = $DB->get_record_select('quiz_feedback', 'quizid = ? AND mingrade <= ? AND maxgrade > ?',
                        [$model['instance']->id, $grade, $grade]);
                    if ($feedback) {
                        $body .= '<div class="feedback">' . $this->content($feedback->feedbacktext,
                            (int)$feedback->feedbacktextformat, $model['path'], $context, 'mod_quiz', 'feedback',
                            (int)$feedback->id) . '</div>';
                    }
                }
            }
        } finally {
            $PAGE = $previouspage;
        }
        personal_data::fragment($this->request, $foruser ?? (int)$this->request->subjectid,
            'quiz:' . $model['cm']->id, $model['path'], $body);
        return $body . (!$attempts ? i18n::get('text_there_are_no_attempts_to_04d48e') : '');
    }

    private function public_grade(object $course, string $module, int $instance, int $userid): ?object {
        $context = \context_course::instance($course->id);
        if (!$course->showgrades || !has_capability('moodle/grade:view', $context, $userid)) {
            return null;
        }
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => $module,
            'iteminstance' => $instance, 'itemnumber' => 0]);
        if (!$item || $item->is_hidden()) {
            return null;
        }
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
        if (!$grade || !$this->grade_visible($item, $grade, $userid)) {
            return null;
        }
        return (object)['item' => $item, 'grade' => $grade];
    }

    private function grade_visible(\grade_item $item, \grade_grade $grade, int $userid): bool {
        if ($item->is_hidden() || $grade->is_hidden()) {
            return false;
        }
        $category = $item->get_parent_category();
        $seen = [];
        while ($category && !isset($seen[$category->id])) {
            $seen[$category->id] = true;
            $parentitem = \grade_item::fetch(['courseid' => $item->courseid,
                'itemtype' => $category->parent ? 'category' : 'course', 'iteminstance' => $category->id]);
            if ($parentitem) {
                $parentgrade = \grade_grade::fetch(['itemid' => $parentitem->id, 'userid' => $userid]);
                if ($parentitem->is_hidden() || ($parentgrade && $parentgrade->is_hidden())) {
                    return false;
                }
            }
            $category = $category->get_parent_category();
        }
        return true;
    }

    /** Participants visible to the exporting teacher, including separate-group restrictions. */
    private function participants(object $course, object $cm, string $capability): array {
        global $USER;
        $context = \context_module::instance($cm->id);
        $users = get_enrolled_users($context, $capability, 0, 'u.*', 'u.id', 0, 0, true);
        if (groups_get_activity_groupmode($cm) === SEPARATEGROUPS &&
                !has_capability('moodle/site:accessallgroups', $context)) {
            $allowed = groups_get_activity_allowed_groups($cm, $USER->id);
            $allowedids = array_keys($allowed);
            foreach ($users as $userid => $user) {
                $groups = groups_get_all_groups($course->id, $userid, $cm->groupingid);
                if (!array_intersect($allowedids, array_keys($groups))) {
                    unset($users[$userid]);
                }
            }
        }
        return $users;
    }

    private function assignment(object $course, array $model, \context_module $context, ?int $foruser = null): string {
        global $DB;
        $cm = $model['cm'];
        $path = $model['path'];
        $assignment = new \assign($context, $cm, $course);
        if ($this->request->kind === 'teacher' && $foruser === null) {
            if (!has_capability('mod/assign:grade', $context)) {
                $this->omit('policy_excluded', i18n::get('text_student_submissions_were_excluded_because_7adaf3'));
                return '';
            }
            $body = i18n::get('text_submissions_grades_and_feedback_are_9ba207');
            foreach ($this->participants($course, $cm, 'mod/assign:submit') as $user) {
                $body .= '<h2>' . $this->e($this->author((int)$user->id)) . '</h2>' .
                    $this->assignment($course, $model, $context, (int)$user->id);
            }
            return $body;
        }
        $userid = $foruser ?? (int)$this->request->subjectid;
        if (!$assignment->can_view_submission($userid)) {
            $this->omit('policy_excluded', i18n::get('submissionnotvisible'));
            return '';
        }
        if ($model['instance']->teamsubmission) {
            $group = $assignment->get_group_submission($userid, 0, false);
            $submissions = $group ? [$group] : [];
        } else {
            $submissions = $DB->get_records('assign_submission', ['assignment' => $cm->instance, 'userid' => $userid],
                'attemptnumber ASC');
        }
        $body = i18n::get('text_your_submissions_b0e831');
        foreach ($submissions as $submission) {
            $this->sourcechecks[] = ['assign_submission', ['id' => $submission->id]];
            $body .= i18n::get('text_submission_cf57a0') . ((int)$submission->attemptnumber + 1) .
                '</span><span class="meta">' . $this->e(userdate($submission->timemodified)) . '</span>';
            $text = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]);
            if ($text) {
                $body .= $this->content($text->onlinetext, (int)$text->onlineformat, $path, $context,
                    'assignsubmission_onlinetext', 'submissions_onlinetext', (int)$submission->id);
            }
            $body .= $this->attachments($this->files($context->id, 'assignsubmission_file', 'submission_files',
                (int)$submission->id), $path) . '</section>';
        }
        if (!$submissions) {
            $body .= i18n::get('text_there_are_no_submissions_to_d9c593');
        }
        $public = $this->public_grade($course, 'assign', (int)$cm->instance, $userid);
        $released = !$model['instance']->markingworkflow ||
            $assignment->get_grading_status($userid) === ASSIGN_MARKING_WORKFLOW_STATE_RELEASED;
        $teacherreview = $this->request->kind === 'teacher' && $foruser !== null &&
            has_capability('mod/assign:grade', $context);
        if (($public && $released) || $teacherreview) {
            $grade = $assignment->get_user_grade($userid, false);
            $value = $teacherreview && $grade ?
                html_entity_decode(strip_tags($assignment->display_grade($grade->grade, false, $userid)), ENT_QUOTES | ENT_HTML5, 'UTF-8') :
                ($public ? grade_format_gradevalue($public->grade->finalgrade, $public->item) : '—');
            $body .= i18n::get('text_grades_and_feedback_09a146') .
                $this->e($value) . '</div>';
            if ($teacherreview && (!$public || !$released)) {
                $body .= i18n::get('text_these_grades_and_feedback_are_f07962');
            }
            if ($grade) {
                $comment = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id]);
                $feedback = $comment ? $this->content($comment->commenttext, (int)$comment->commentformat, $path, $context,
                    'assignfeedback_comments', 'feedback', (int)$grade->id) : '';
                $feedback .= $this->attachments($this->files($context->id, 'assignfeedback_file', 'feedback_files',
                    (int)$grade->id), $path);
                $body .= $feedback;
                personal_data::fragment($this->request, (int)$grade->grader, 'assignment-feedback:' . $grade->id, $path, $feedback);
            }
            $body .= '</div>';
        }
        personal_data::fragment($this->request, $userid, 'assignment:' . $cm->id, $path, $body);
        return $body;
    }

    private function grades(object $course, string $path, ?int $foruser = null): string {
        global $USER;
        if ($this->request->kind === 'teacher' && $foruser === null) {
            $context = \context_course::instance($course->id);
            if (!has_capability('moodle/grade:viewall', $context)) {
                $this->omit('policy_excluded', i18n::get('text_grade_report_access_is_not_942aef'));
                return i18n::get('text_there_are_no_grades_to_b03025');
            }
            $body = i18n::get('text_grades_are_saved_according_to_76c865');
            $users = get_enrolled_users($context, 'moodle/grade:view', 0, 'u.*', 'u.id', 0, 0, true);
            foreach ($users as $user) {
                if (has_capability('moodle/grade:viewall', $context, $user->id)) {
                    continue;
                }
                if (groups_get_course_groupmode($course) === SEPARATEGROUPS &&
                        !has_capability('moodle/site:accessallgroups', $context) &&
                        !array_intersect(array_keys(groups_get_all_groups($course->id, $USER->id)),
                            array_keys(groups_get_all_groups($course->id, $user->id)))) {
                    continue;
                }
                $body .= '<h2>' . $this->e($this->author((int)$user->id)) . '</h2>' . $this->grades($course, $path, (int)$user->id);
            }
            return $body;
        }
        $userid = $foruser ?? (int)$this->request->subjectid;
        $context = \context_course::instance($course->id);
        $teacherreview = $this->request->kind === 'teacher' && $foruser !== null &&
            has_capability('moodle/grade:viewall', $context);
        $canseehidden = $teacherreview && has_capability('moodle/grade:viewhidden', $context);
        if (!$teacherreview && (!$course->showgrades || !has_capability('moodle/grade:view', \context_course::instance($course->id), $userid))) {
            $this->omit('policy_excluded', i18n::get('gradebooknotvisible'));
            return i18n::get('text_no_grades_have_been_published_932dd8');
        }
        $items = \grade_item::fetch_all(['courseid' => $course->id]) ?: [];
        $hasprivate = false;
        foreach ($items as $item) {
            $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
            if (!$canseehidden && ($item->is_hidden() || ($grade && !$this->grade_visible($item, $grade, $userid)))) {
                $hasprivate = true;
            }
        }
        $body = '<p class="lead">' . ($teacherreview ? i18n::get('text_these_grades_are_visible_under_38ec44') :
            i18n::get('text_these_grades_were_published_to_48b5c6')) . '</p>' .
            i18n::get('text_item_grade_feedback_e9fef3');
        foreach ($items as $item) {
            $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
            if (!$grade || (!$canseehidden && !$this->grade_visible($item, $grade, $userid))) {
                continue;
            }
            if ($hasprivate && in_array($item->itemtype, ['course', 'category'], true)) {
                $this->omit('policy_excluded', i18n::get('hiddenaggregate'));
                continue;
            }
            if ($item->itemtype === 'mod') {
                $visible = false;
                foreach ($this->courses[$course->id]['models'] as $model) {
                    if ($model['cm']->modname === $item->itemmodule && $model['cm']->instance == $item->iteminstance) {
                        $visible = true;
                        break;
                    }
                }
                if (!$visible) {
                    continue;
                }
            }
            $feedback = $this->content($grade->feedback ?? '', (int)$grade->feedbackformat, $path,
                $context, 'grade', 'feedback', (int)$grade->id);
            personal_data::fragment($this->request, (int)$grade->usermodified,
                'grade-feedback:' . $grade->id, $path, $feedback);
            $hidden = !$this->grade_visible($item, $grade, $userid);
            $body .= '<tr><td>' . $this->e($item->get_name()) . ($hidden ? i18n::get('text_hidden_from_students_22613f') : '') .
                '</td><td class="grade">' .
                $this->e(grade_format_gradevalue($grade->finalgrade, $item)) . '</td><td>' .
                $feedback . '</td></tr>';
        }
        $body .= '</tbody></table>';
        personal_data::fragment($this->request, $userid, 'grades:' . $course->id, $path, $body);
        return $body;
    }

    private function finish(): void {
        global $DB, $CFG;
        $this->cmid = $this->courseid = 0;
        $activitycount = array_sum(array_map(fn($c) => count($c['models']), $this->courses));
        $body = i18n::get('text_what_you_learned_what_you_94c922') .
            '<div class="stats"><div class="stat"><strong>' . count($this->courses) . i18n::get('text_courses_63decf') .
            '<div class="stat"><strong>' . $activitycount . i18n::get('text_materials_and_activities_a22826') .
            count($this->sourceids) . i18n::get('text_referenced_files_your_learning_records_272623');
        foreach ($this->courses as $id => $data) {
            $body .= '<section class="card"><span class="tag">COURSE</span><span class="meta">' .
                count($data['models']) . i18n::get('text_materials_and_activities_a_href_7e5ca9') . $id . '/index.html">' .
                $this->e($data['course']->fullname) . '</a></h2><p class="meta">' . $this->e($data['course']->shortname) .
                '</p><a class="arrow" href="courses/c' . $id . i18n::get('text_index_html_open_course_records_92a8d0');
        }
        $this->documents['index.html'] = ['title' => i18n::get('text_your_learning_carried_forward_b811a3'), 'body' => $body . '</div>' .
            i18n::get('text_see_about_this_archive_for_cb282b'),
            'eyebrow' => 'MY LEARNING ARCHIVE', 'courseid' => 0, 'cmid' => 0];
        $omissions = $DB->get_records('local_tomb_omission', ['requestid' => $this->request->id], 'id ASC');
        $body = '<p class="lead">' . count($omissions) . i18n::get('text_archive_notes_confirmed_deletions_were_7bde58') .
            i18n::get('text_category_details_3a2856');
        foreach ($omissions as $omission) {
            $body .= '<tr><td>' . $this->e(get_string_manager()->string_exists('reason_' . $omission->reason, 'local_tomb') ? i18n::get('reason_' . $omission->reason) : $omission->reason) . '</td><td>' . $this->e($omission->detail) . '</td></tr>';
        }
        $this->documents['omissions.html'] = ['title' => i18n::get('text_about_this_archive_e485da'), 'body' => $body . '</table>' .
            i18n::get('text_pseudonyms_in_author_fields_do_20e37c'),
            'eyebrow' => 'ARCHIVE NOTES', 'courseid' => 0, 'cmid' => 0];
        if ($this->request->revisionof) {
            $oldrows = $DB->get_records('local_tomb_entry', ['requestid' => $this->request->revisionof]);
            $old = array_map(fn($row) => $row->zippath, $oldrows);
            $current = array_keys($this->documents);
            $diff = i18n::get('text_keep_the_earlier_version_and_3b1643');
            foreach (array_diff($current, $old) as $path) {
                $diff .= '<li>' . $this->e($path) . '</li>';
            }
            $diff .= i18n::get('text_pages_not_included_this_time_c0573b');
            foreach ($old as $path) {
                if (str_ends_with($path, '.html') && !in_array($path, $current, true) && $path !== 'revision-diff.html') {
                    $diff .= '<li>' . $this->e($path) . '</li>';
                }
            }
            $diff .= i18n::get('text_pages_with_changed_content_ef0a98');
            $unavailable = false;
            foreach ($oldrows as $row) {
                if (!isset($this->documents[$row->zippath]) || $row->sourcetype !== 'inline') {
                    continue;
                }
                $file = get_file_storage()->get_file_by_id($row->fileid);
                if (!$file) {
                    $unavailable = true;
                    continue;
                }
                try {
                    $document = $this->documents[$row->zippath];
                    $oldcontent = gzinflate($file->get_content());
                    $newcontent = renderer::page($this->request, $row->zippath, $document['title'],
                        $document['body'], $document['eyebrow']);
                    if ($oldcontent === false || html::article_digest($oldcontent) !== html::article_digest($newcontent)) {
                        $diff .= '<li><a href="' . $row->zippath . '">' . $this->e($document['title']) . '</a></li>';
                    }
                } catch (\Throwable $e) {
                    $unavailable = true;
                }
            }
            $diff .= i18n::get('text_content_comparison_excludes_the_collection_0da89b');
            if ($unavailable) {
                $diff .= i18n::get('text_some_content_could_not_be_15aefd');
            }
            $this->documents['revision-diff.html'] = ['title' => i18n::get('text_changes_from_the_previous_version_4acf68'), 'body' => $diff,
                'eyebrow' => 'REVISION', 'courseid' => 0, 'cmid' => 0];
            $this->documents['index.html']['body'] .= i18n::get('text_view_changes_from_the_previous_93f5d3');
        }
        $expectedpages = 2 + 2 * count($this->courses) + $activitycount + ($this->request->revisionof ? 1 : 0);
        if (count($this->documents) !== $expectedpages) {
            throw new \RuntimeException('Rendered page count does not match independently enumerated targets');
        }
        $this->request->expected = json_encode(['courses' => count($this->courses), 'activities' => $activitycount,
            'pages' => $expectedpages], JSON_THROW_ON_ERROR);
        $digests = [];
        $rendered = [];
        foreach ($this->documents as $path => $document) {
            $content = renderer::page($this->request, $path, $document['title'], $document['body'], $document['eyebrow']);
            $this->storage->add_text($path, $content, $document['courseid'], $document['cmid']);
            $digests[$path] = hash('sha256', $content);
            $rendered[$path] = $content;
        }
        $assets = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($CFG->dirroot . '/local/tomb/assets',
            \FilesystemIterator::SKIP_DOTS));
        foreach ($assets as $asset) {
            if (!$asset->isFile()) {
                continue;
            }
            $relative = substr($asset->getPathname(), strlen($CFG->dirroot . '/local/tomb/assets/'));
            $path = '_assets/' . $relative;
            $content = file_get_contents($asset->getPathname());
            $this->storage->add_text($path, $content);
            $digests[$path] = hash('sha256', $content);
        }
        $pages = $DB->count_records_select('local_tomb_entry', 'requestid = ? AND zippath LIKE ?',
            [$this->request->id, '%.html']);
        if ($pages !== $expectedpages) {
            throw new \RuntimeException('Expected page count does not match stored entries');
        }
        $entries = $DB->get_records('local_tomb_entry', ['requestid' => $this->request->id], 'id ASC');
        html::check_links($rendered, array_map(fn($entry) => $entry->zippath, $entries));
        $this->request->actual = json_encode(['pages' => $pages, 'entries' => count($entries) + 2,
            'validated_links' => true], JSON_THROW_ON_ERROR);
        $manifest = ['schema' => 'moodle-static-archive/1.0', 'request_id' => (int)$this->request->id,
            'revision' => ['supersedes' => (int)$this->request->revisionof], 'source' => $CFG->wwwroot,
            'requested_at' => gmdate('c', $this->request->timecreated),
            'collection_started' => gmdate('c', $this->request->timestarted),
            'collection_finished' => gmdate('c', $this->request->timecollected),
            'output_language' => $this->request->outputlang ?? 'ja',
            'generated' => gmdate('c', $this->request->timefinished), 'policy' => $this->request->policy,
            'courses' => array_keys($this->courses), 'expected' => json_decode($this->request->expected, true),
            'omissions' => array_map(fn($o) => ['courseid' => (int)$o->courseid, 'cmid' => (int)$o->cmid,
                'reason' => $o->reason, 'detail' => $o->detail], array_values($omissions)),
            'entries' => array_values(array_map(fn($entry) => ['path' => $entry->zippath, 'bytes' => (int)$entry->size,
                'crc32' => sprintf('%08x', $entry->crc), 'method' => (int)$entry->method], $entries)),
            'integrity' => ['sha256' => $digests, 'note' => 'Shared files are verified by ZIP CRC32; manifest and README are excluded from this list.']];
        $this->storage->add_text('manifest.json', json_encode($manifest,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->storage->add_text('README.txt', i18n::get('text_tomb_learning_archive_fully_extract_b6ddff') .
            i18n::get('text_no_network_connection_or_moodle_99aa5d') .
            i18n::get('text_the_content_was_obtained_during_80363d') .
            i18n::get('text_keep_the_original_zip_and_3d2d7f'));
    }
}
