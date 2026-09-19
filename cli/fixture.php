<?php
// License: GNU GPL v3 or later.
// Optional, isolated demonstration data. Never resets the site or changes existing courses.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/group/lib.php');
[$options, $unknown] = cli_get_params(['create' => false, 'secrets' => '', 'help' => false], ['h' => 'help']);
if (!$options['create'] || !$options['secrets'] || $unknown) {
    cli_writeln('php local/tomb/cli/fixture.php --create --secrets=/private/path.json');
    exit($options['help'] ? 0 : 1);
}
if (get_config('local_tomb', 'operationmode') && !in_array(get_config('local_tomb', 'operationmode'), ['disabled', 'rehearsal'], true)) {
    cli_error('Only disabled or rehearsal installations can create demonstration fixtures');
}
$secretpath = $options['secrets'];
$secrets = json_decode(file_get_contents($secretpath), true, 512, JSON_THROW_ON_ERROR);
if (strlen($secrets['password'] ?? '') < 16) {
    cli_error('A private fixture password of at least 16 characters is required');
}
core\session\manager::set_user(get_admin());
$system = context_system::instance();
$cohort = $DB->get_record('cohort', ['idnumber' => 'tomb-alpha-fixture']);
if (!$cohort) {
    $cohort = (object)['contextid' => $system->id, 'name' => 'Tomb Alpha 検証対象',
        'idnumber' => 'tomb-alpha-fixture', 'description' => 'Created by local_tomb fixture CLI',
        'descriptionformat' => FORMAT_PLAIN, 'visible' => 0, 'component' => ''];
    $cohort->id = cohort_add_cohort($cohort);
}
$users = [];
foreach (['learner' => ['ひなた', '青木'], 'other' => ['湊', '高橋'], 'teacher' => ['由紀', '佐藤']] as $key => $names) {
    $username = 'tomb.alpha.' . $key;
    $user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]);
    if (!$user) {
        $user = (object)['username' => $username, 'password' => $secrets['password'], 'auth' => 'manual',
            'firstname' => $names[0], 'lastname' => $names[1], 'email' => $username . '@example.invalid',
            'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id, 'lang' => 'ja',
            'idnumber' => 'tomb-alpha-fixture-' . $key];
        $user->id = user_create_user($user);
    } else if ($user->idnumber !== 'tomb-alpha-fixture-' . $key) {
        cli_error('Refusing to reuse an unrelated user');
    }
    cohort_add_member($cohort->id, $user->id);
    $users[$key] = $user;
}
$course = $DB->get_record('course', ['idnumber' => 'tomb-alpha-fixture']);
if (!$course) {
    $category = $DB->get_field_sql('SELECT MIN(id) FROM {course_categories}');
    $course = create_course((object)['category' => $category, 'fullname' => '学びをつなぐ — データサイエンス入門',
        'shortname' => 'TOMB-ALPHA', 'idnumber' => 'tomb-alpha-fixture', 'format' => 'topics', 'numsections' => 3,
        'summary' => '<p>Tomb の動作検証・コンペ提出用デモコースです。すべて架空の学習記録です。</p>',
        'summaryformat' => FORMAT_HTML, 'visible' => 1, 'showgrades' => 1, 'enablecompletion' => 0,
        'startdate' => time() - 86400 * 90, 'enddate' => 0]);
}
$enrol = enrol_get_plugin('manual');
$instance = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $course->id]);
if (!$instance) {
    $enrolid = $enrol->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED]);
    $instance = $DB->get_record('enrol', ['id' => $enrolid], '*', MUST_EXIST);
}
foreach ($users as $key => $user) {
    $role = $DB->get_record('role', ['shortname' => $key === 'teacher' ? 'editingteacher' : 'student'], '*', MUST_EXIST);
    if (!$DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id])) {
        $enrol->enrol_user($instance, $user->id, $role->id);
    }
}
function tomb_fixture_module(string $key, string $module, string $name, int $section, array $extra = []): object {
    global $course, $DB;
    $cm = $DB->get_record('course_modules', ['course' => $course->id, 'idnumber' => 'tomb-alpha-' . $key]);
    if ($cm) {
        return get_coursemodule_from_id($module, $cm->id, $course->id, false, MUST_EXIST);
    }
    $defaults = ['modulename' => $module, 'module' => $DB->get_field('modules', 'id', ['name' => $module]),
        'name' => $name, 'section' => $section, 'cmidnumber' => 'tomb-alpha-' . $key, 'visible' => 1,
        'groupmode' => 0, 'groupingid' => 0, 'intro' => '', 'introformat' => FORMAT_HTML,
        'completion' => 0, 'gradecat' => 0];
    $info = add_moduleinfo((object)array_merge($defaults, $extra), $course);
    return get_coursemodule_from_id($module, $info->coursemodule, $course->id, false, MUST_EXIST);
}
function tomb_fixture_file(object $cm, string $component, string $area, int $item, string $name, string $text): void {
    $fs = get_file_storage();
    $context = context_module::instance($cm->id);
    if (!$fs->file_exists($context->id, $component, $area, $item, '/', $name)) {
        $fs->create_file_from_string(['contextid' => $context->id, 'component' => $component, 'filearea' => $area,
            'itemid' => $item, 'filepath' => '/', 'filename' => $name], $text);
    }
}
$label = tomb_fixture_module('welcome', 'label', '学びの地図', 0, ['intro' =>
    '<h3>問いを立て、データを読み、言葉にする。</h3><p>このコースでは、データを使って身のまわりの課題を考えます。</p>']);
$page = tomb_fixture_module('page', 'page', '01 データと出会う — 平均から分かること', 1, ['display' => 0,
    'printintro' => 0, 'printlastmodified' => 0, 'contentformat' => FORMAT_HTML, 'content' =>
    '<h2>データの向こうにある問い</h2><p>同じ平均値でも、データの広がりは異なります。数値を比較するときは、集めた目的や条件も確認しましょう。</p>' .
    '<h3>平均とばらつき</h3><p>標本平均は \\(\\bar{x}=\\frac{1}{n}\\sum_{i=1}^{n}x_i\\) と表せます。</p>' .
    '<table><tr><th>観測</th><th>A組</th><th>B組</th></tr><tr><td>1</td><td>5</td><td>1</td></tr>' .
    '<tr><td>2</td><td>6</td><td>6</td></tr><tr><td>3</td><td>7</td><td>11</td></tr></table>' .
    '<p>平均はいずれも6です。分散を求めて比較してみましょう。</p><img alt="埋め込み画像の保存確認" src="@@PLUGINFILE@@/sample.png">']);
tomb_fixture_file($page, 'mod_page', 'content', 0, 'sample.png',
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2ioAAAAASUVORK5CYII='));
$folder = tomb_fixture_module('folder', 'folder', '演習データと参考資料', 1, ['files' => 0, 'display' => 0,
    'intro' => '<p>演習で使ったデータと、その読み方をまとめました。</p>']);
tomb_fixture_file($folder, 'mod_folder', 'content', 0, '観測データ.csv', "group,value\nA,5\nA,6\nA,7\nB,1\nB,6\nB,11\n");
tomb_fixture_file($folder, 'mod_folder', 'content', 0, '読み方.txt', "列 group は観測群、value は測定値です。Tomb alpha fixture.\n");
$resource = tomb_fixture_module('resource', 'resource', '授業ノート — データの読み方', 1, ['display' => 0,
    'files' => 0, 'printintro' => 1, 'intro' => '<p>授業で配布した資料です。</p>']);
tomb_fixture_file($resource, 'mod_resource', 'content', 0, '授業ノート.txt', "Tomb alpha material\n平均だけでなく分散や測定条件を確認しましょう。\n");
$assignoptions = ['submissiondrafts' => 0, 'requiresubmissionstatement' => 0, 'sendnotifications' => 0,
    'sendlatenotifications' => 0, 'sendstudentnotifications' => 0, 'duedate' => 0, 'cutoffdate' => 0,
    'gradingduedate' => 0, 'allowsubmissionsfromdate' => 0, 'grade' => 100, 'teamsubmission' => 0,
    'requireallteammemberssubmit' => 0, 'blindmarking' => 0, 'markingworkflow' => 0, 'markingallocation' => 0,
    'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 1,
    'assignsubmission_file_maxfiles' => 3, 'assignsubmission_file_maxsizebytes' => 1048576,
    'assignfeedback_comments_enabled' => 1, 'assignfeedback_file_enabled' => 1,
    'intro' => '<p>平均が等しい2つの集団について、ばらつきの違いと考察を400字程度でまとめてください。</p>'];
$assigncm = tomb_fixture_module('assignment', 'assign', '02 平均だけでは分からないこと', 2, $assignoptions);
$assignment = new assign(context_module::instance($assigncm->id), $assigncm, $course);
foreach (['learner', 'other'] as $key) {
    $user = $users[$key];
    if (!$assignment->get_user_submission($user->id, false)) {
        core\session\manager::set_user(core_user::get_user($user->id, '*', MUST_EXIST));
        $draft = file_get_unused_draft_itemid();
        $data = (object)['onlinetext_editor' => ['text' => $key === 'learner' ?
            '<p>2つの集団の平均はいずれも6ですが、A組の値は平均付近に集中しています。B組では値の差が大きく、平均だけではこの違いを捉えられません。</p>' .
            '<p>比較するときは、標準偏差や分布も併せて示す必要があると考えました。</p><!--TOMB_OWN_SUBMISSION-->' :
            '<p>TOMB_OTHER_STUDENT_SECRET</p>', 'format' => FORMAT_HTML, 'itemid' => $draft], 'files_filemanager' => 0];
        $notices = [];
        if (!$assignment->save_submission($data, $notices)) {
            throw new RuntimeException('Fixture submission failed: ' . implode('; ', $notices));
        }
        $submission = $assignment->get_user_submission($user->id, false);
        tomb_fixture_file($assigncm, 'assignsubmission_file', 'submission_files', $submission->id,
            '考察レポート.txt', $key === 'learner' ? 'TOMB_OWN_FILE: 分布の違いを説明したレポートです。' : 'TOMB_OTHER_FILE_SECRET');
    }
}
core\session\manager::set_user(get_admin());
if (!$assignment->get_user_grade($users['learner']->id, false)) {
    $assignment->save_grade($users['learner']->id, (object)['grade' => 92, 'attemptnumber' => -1,
        'addattempt' => 0, 'sendstudentnotifications' => 0, 'assignfeedbackcomments_editor' => [
            'text' => '<p>平均と分布の違いを的確に説明できています。次は、分散の数値を添えると、さらに説得力が増します。</p><!--TOMB_PUBLIC_FEEDBACK-->',
            'format' => FORMAT_HTML], 'files_filemanager' => 0]);
    $grade = $assignment->get_user_grade($users['learner']->id, false);
    tomb_fixture_file($assigncm, 'assignfeedback_file', 'feedback_files', $grade->id, '講評.txt', 'TOMB_FEEDBACK_FILE');
}
$hidden = tomb_fixture_module('hidden', 'page', 'TOMB_HIDDEN_ACTIVITY_SECRET', 3, ['visible' => 0, 'display' => 0,
    'printintro' => 0, 'printlastmodified' => 0, 'contentformat' => FORMAT_HTML, 'content' => '<p>TOMB_HIDDEN_CONTENT_SECRET</p>']);
$privateitem = grade_item::fetch(['courseid' => $course->id, 'idnumber' => 'tomb-private-grade']);
if (!$privateitem) {
    $privateitem = new grade_item(['courseid' => $course->id, 'itemtype' => 'manual', 'itemname' => 'TOMB_PRIVATE_GRADE_SECRET',
        'idnumber' => 'tomb-private-grade', 'gradetype' => GRADE_TYPE_VALUE, 'grademax' => 100, 'grademin' => 0, 'hidden' => 1], false);
    $privateitem->insert();
    $privateitem->update_final_grade($users['learner']->id, 47, 'local_tomb', 'TOMB_PRIVATE_FEEDBACK_SECRET', FORMAT_PLAIN);
}
$forumcm = tomb_fixture_module('forum', 'forum', '03 データの見方を共有する', 2, ['type' => 'general',
    'forcesubscribe' => FORUM_DISALLOWSUBSCRIBE, 'assessed' => 0, 'scale' => 0, 'grade_forum' => 0,
    'intro' => '<p>同じ数値から、どのような気づきがありましたか。意見を交換しましょう。</p>']);
if (!$DB->record_exists('forum_discussions', ['forum' => $forumcm->instance])) {
    core\session\manager::set_user(core_user::get_user($users['other']->id, '*', MUST_EXIST));
    $discussionid = forum_add_discussion((object)['forum' => $forumcm->instance, 'course' => $course->id,
        'name' => '平均が同じなら、何を比較すればよい？', 'message' => '<p>グラフにすると、値の散らばりがよく分かりました。中央値も比較してみたいです。</p><!--TOMB_VISIBLE_FORUM-->',
        'messageformat' => FORMAT_HTML, 'messagetrust' => 0, 'mailnow' => 0, 'groupid' => -1,
        'timestart' => 0, 'timeend' => 0, 'timelocked' => 0]);
    $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid], '*', MUST_EXIST);
    tomb_fixture_file($forumcm, 'mod_forum', 'attachment', $discussion->firstpost, '観察メモ.txt', 'TOMB_FORUM_ATTACHMENT');
    core\session\manager::set_user(core_user::get_user($users['learner']->id, '*', MUST_EXIST));
    forum_add_new_post((object)['discussion' => $discussionid, 'parent' => $discussion->firstpost,
        'subject' => 'Re: 平均が同じなら、何を比較すればよい？', 'message' => '<p>私は標準偏差を計算しました。数値とグラフを組み合わせると、違いを説明しやすいですね。</p>',
        'messageformat' => FORMAT_HTML, 'messagetrust' => 0, 'itemid' => 0, 'mailnow' => 0], null);
    // A timed discussion which must not appear for the learner.
    core\session\manager::set_user(core_user::get_user($users['teacher']->id, '*', MUST_EXIST));
    forum_add_discussion((object)['forum' => $forumcm->instance, 'course' => $course->id,
        'name' => 'TOMB_TIMED_FORUM_SECRET', 'message' => '<p>TOMB_TIMED_FORUM_BODY_SECRET</p>',
        'messageformat' => FORMAT_HTML, 'messagetrust' => 0, 'mailnow' => 0, 'groupid' => -1,
        'timestart' => time() + 86400 * 90, 'timeend' => 0, 'timelocked' => 0]);
}
core\session\manager::set_user(get_admin());
$quizoptions = ['timeopen' => 0, 'timeclose' => 0, 'preferredbehaviour' => 'deferredfeedback', 'attempts' => 0,
    'attemptonlast' => 0, 'grademethod' => QUIZ_GRADEHIGHEST, 'decimalpoints' => 2, 'questiondecimalpoints' => -1,
    'questionsperpage' => 1, 'shuffleanswers' => 1, 'sumgrades' => 0, 'grade' => 100, 'timelimit' => 0,
    'overduehandling' => 'autosubmit', 'graceperiod' => 86400, 'quizpassword' => '', 'subnet' => '',
    'browsersecurity' => '', 'delay1' => 0, 'delay2' => 0, 'showuserpicture' => 0, 'showblocks' => 0,
    'navmethod' => QUIZ_NAVMETHOD_FREE, 'intro' => '<p>既存の検証用問題を再利用した、受験記録の保存テストです。</p>'];
foreach (['during', 'immediately', 'open', 'closed'] as $when) {
    foreach (['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback'] as $field) {
        $quizoptions[$field . $when] = 1;
    }
}
$quizcm = tomb_fixture_module('quiz', 'quiz', '04 理解を確かめる — 練習問題', 3, $quizoptions);
if (!$DB->record_exists('quiz_slots', ['quizid' => $quizcm->instance])) {
    $questionids = array_keys($DB->get_records_sql("SELECT q.id FROM {question} q JOIN {question_versions} v ON v.questionid = q.id
        WHERE q.qtype IN ('multichoice', 'numerical', 'truefalse') AND v.status = 'ready' ORDER BY q.id", [], 0, 3));
    if (!$questionids) {
        cli_error('Fixture quiz requires at least one existing test question');
    }
    $quizrecord = $DB->get_record('quiz', ['id' => $quizcm->instance], '*', MUST_EXIST);
    foreach ($questionids as $questionid) {
        quiz_add_quiz_question($questionid, $quizrecord);
    }
    \mod_quiz\quiz_settings::create($quizrecord->id)->get_grade_calculator()->recompute_quiz_sumgrades();
}
rebuild_course_cache($course->id, true);
core\session\manager::set_user(core_user::get_user($users['learner']->id, '*', MUST_EXIST));
foreach ([1, 2] as $number) {
    if (!$DB->record_exists('quiz_attempts', ['quiz' => $quizcm->instance, 'userid' => $users['learner']->id, 'attempt' => $number])) {
        $quizobject = \mod_quiz\quiz_settings::create($quizcm->instance, $users['learner']->id);
        $attempt = quiz_prepare_and_start_new_attempt($quizobject, $number, null);
        $attemptobject = \mod_quiz\quiz_attempt::create($attempt->id);
        $responses = [];
        foreach ($attemptobject->get_slots() as $slot) {
            $qa = $attemptobject->get_question_attempt($slot);
            $responses[$qa->get_control_field_name('sequencecheck')] = $qa->get_sequence_check_count();
            foreach ($qa->get_correct_response() as $name => $value) {
                $responses[$qa->get_qt_field_name($name)] = $value;
            }
        }
        $attemptobject->process_submitted_actions(time(), false, $responses);
        if ($number === 1) {
            $attemptobject->process_submit(time(), false);
            $attemptobject->process_grade_submission(time());
        }
    }
}
core\session\manager::set_user(get_admin());
$groups = [];
foreach (['a' => [$users['learner'], $users['teacher']], 'b' => [$users['other']]] as $key => $members) {
    $group = $DB->get_record('groups', ['courseid' => $course->id, 'idnumber' => 'tomb-alpha-' . $key]);
    if (!$group) {
        $group = (object)['courseid' => $course->id, 'name' => 'Tomb 検証グループ ' . strtoupper($key),
            'idnumber' => 'tomb-alpha-' . $key, 'description' => '', 'descriptionformat' => FORMAT_HTML];
        $group->id = groups_create_group($group);
    }
    foreach ($members as $member) {
        groups_add_member($group->id, $member->id);
    }
    $groups[$key] = $group;
}
$groupforum = tomb_fixture_module('groupforum', 'forum', 'グループでの学習記録', 2, ['type' => 'general',
    'groupmode' => SEPARATEGROUPS, 'forcesubscribe' => FORUM_DISALLOWSUBSCRIBE, 'assessed' => 0, 'scale' => 0, 'grade_forum' => 0]);
foreach (['a' => $users['learner'], 'b' => $users['other']] as $key => $author) {
    if (!$DB->record_exists('forum_discussions', ['forum' => $groupforum->instance, 'groupid' => $groups[$key]->id])) {
        core\session\manager::set_user(core_user::get_user($author->id, '*', MUST_EXIST));
        forum_add_discussion((object)['forum' => $groupforum->instance, 'course' => $course->id,
            'name' => 'グループの振り返り', 'message' => $key === 'a' ? '<p>ばらつきの説明を分担してまとめました。</p><!--TOMB_OWN_GROUP-->' :
                '<p>TOMB_OTHER_GROUP_SECRET</p>', 'messageformat' => FORMAT_HTML, 'messagetrust' => 0,
            'mailnow' => 0, 'groupid' => $groups[$key]->id, 'timestart' => 0, 'timeend' => 0, 'timelocked' => 0]);
    }
}
core\session\manager::set_user(get_admin());
$qanda = tomb_fixture_module('qanda', 'forum', '発展問題 — 自分の考えを言葉にする', 3, ['type' => 'qanda',
    'forcesubscribe' => FORUM_DISALLOWSUBSCRIBE, 'assessed' => 0, 'scale' => 0, 'grade_forum' => 0]);
if (!$DB->record_exists('forum_discussions', ['forum' => $qanda->instance])) {
    $discussionid = forum_add_discussion((object)['forum' => $qanda->instance, 'course' => $course->id,
        'name' => '平均から読み取れない情報を挙げてください', 'message' => '<p>まず自分の考えを投稿してから、他の参加者の意見を読んでみましょう。</p><!--TOMB_QANDA_QUESTION-->',
        'messageformat' => FORMAT_HTML, 'messagetrust' => 0, 'mailnow' => 0, 'groupid' => -1,
        'timestart' => 0, 'timeend' => 0, 'timelocked' => 0]);
    $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid], '*', MUST_EXIST);
    core\session\manager::set_user(core_user::get_user($users['other']->id, '*', MUST_EXIST));
    forum_add_new_post((object)['discussion' => $discussionid, 'parent' => $discussion->firstpost,
        'subject' => '投稿済みの考察', 'message' => '<p>TOMB_QANDA_REPLY_SECRET</p>', 'messageformat' => FORMAT_HTML,
        'messagetrust' => 0, 'itemid' => 0, 'mailnow' => 0], null);
}
core\session\manager::set_user(get_admin());
$formatscm = tomb_fixture_module('quizformats', 'quiz', '05 さまざまな問いで復習する', 3, $quizoptions);
if (!$DB->record_exists('quiz_slots', ['quizid' => $formatscm->instance])) {
    $quizrecord = $DB->get_record('quiz', ['id' => $formatscm->instance], '*', MUST_EXIST);
    foreach (['multichoice', 'truefalse', 'numerical', 'shortanswer', 'match'] as $type) {
        $ids = $DB->get_fieldset_sql('SELECT q.id FROM {question} q JOIN {question_versions} v ON v.questionid = q.id
            WHERE q.qtype = ? AND v.status = ? ORDER BY q.id', [$type, 'ready'], 0, 1);
        if ($ids) {
            quiz_add_quiz_question(reset($ids), $quizrecord);
        }
    }
    \mod_quiz\quiz_settings::create($quizrecord->id)->get_grade_calculator()->recompute_quiz_sumgrades();
}
rebuild_course_cache($course->id, true);
core\session\manager::set_user(core_user::get_user($users['learner']->id, '*', MUST_EXIST));
if (!$DB->record_exists('quiz_attempts', ['quiz' => $formatscm->instance, 'userid' => $users['learner']->id])) {
    $quizobject = \mod_quiz\quiz_settings::create($formatscm->instance, $users['learner']->id);
    $attempt = quiz_prepare_and_start_new_attempt($quizobject, 1, null);
    $attemptobject = \mod_quiz\quiz_attempt::create($attempt->id);
    $responses = [];
    foreach ($attemptobject->get_slots() as $slot) {
        $qa = $attemptobject->get_question_attempt($slot);
        $responses[$qa->get_control_field_name('sequencecheck')] = $qa->get_sequence_check_count();
        foreach ($qa->get_correct_response() as $name => $value) {
            $responses[$qa->get_qt_field_name($name)] = $value;
        }
    }
    $attemptobject->process_submitted_actions(time(), false, $responses);
    $attemptobject->process_submit(time(), false);
    $attemptobject->process_grade_submission(time());
}
core\session\manager::set_user(get_admin());
set_config('rehearsalcohortid', $cohort->id, 'local_tomb');
// Keep test sentinels machine-checkable without showing implementation labels in the demo UI.
$ownsubmission = $assignment->get_user_submission($users['learner']->id, false);
if ($ownsubmission && ($text = $DB->get_record('assignsubmission_onlinetext', ['submission' => $ownsubmission->id]))) {
    $DB->set_field('assignsubmission_onlinetext', 'onlinetext', str_replace('<p>TOMB_OWN_SUBMISSION</p>',
        '<!--TOMB_OWN_SUBMISSION-->', $text->onlinetext), ['id' => $text->id]);
}
$owngrade = $assignment->get_user_grade($users['learner']->id, false);
if ($owngrade && ($comment = $DB->get_record('assignfeedback_comments', ['grade' => $owngrade->id]))) {
    $DB->set_field('assignfeedback_comments', 'commenttext', str_replace('<p>TOMB_PUBLIC_FEEDBACK</p>',
        '<!--TOMB_PUBLIC_FEEDBACK-->', $comment->commenttext), ['id' => $comment->id]);
}
foreach ($DB->get_records_sql('SELECT p.id, p.message FROM {forum_posts} p JOIN {forum_discussions} d ON d.id = p.discussion
        WHERE d.forum = ?', [$forumcm->instance]) as $post) {
    $DB->set_field('forum_posts', 'message', str_replace('<p>TOMB_VISIBLE_FORUM</p>',
        '<!--TOMB_VISIBLE_FORUM-->', $post->message), ['id' => $post->id]);
}
$pagecontent = $DB->get_field('page', 'content', ['id' => $page->instance]);
if (!str_contains($pagecontent, 'TOMB_CROSS_LINK')) {
    $url = moodle_url::make_pluginfile_url(context_module::instance($resource->id)->id, 'mod_resource', 'content',
        0, '/', '授業ノート.txt');
    $pagecontent .= '<!--TOMB_CROSS_LINK--><p><a href="' . $url->out() . '">授業ノートを読む</a> ／ <a href="' .
        (new moodle_url('/mod/folder/view.php', ['id' => $folder->id]))->out() . '">演習データへ</a></p>';
    $DB->set_field('page', 'content', $pagecontent, ['id' => $page->instance]);
}
set_config('operationmode', 'rehearsal', 'local_tomb');
set_config('fixturecourseid', $course->id, 'local_tomb');
$secrets['users'] = array_map(fn($u) => ['id' => (int)$u->id, 'username' => $u->username], $users);
$secrets['courseid'] = (int)$course->id;
$secrets['cohortid'] = (int)$cohort->id;
file_put_contents($secretpath, json_encode($secrets, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
chmod($secretpath, 0600);
local_tomb\local\audit::add('fixture_created', 0, ['course' => $course->id, 'cohort' => $cohort->id]);
cli_writeln('Fixture ready: course=' . $course->id . ', cohort=' . $cohort->id . '; login details remain in private file.');
