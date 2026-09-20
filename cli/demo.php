<?php
// License: GNU GPL v3 or later. Separate presentation data; preserves the regression fixtures.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
foreach (['/lib/clilib.php', '/course/lib.php', '/course/modlib.php', '/mod/assign/locallib.php',
        '/mod/forum/lib.php', '/mod/quiz/locallib.php', '/lib/gradelib.php'] as $library) {require_once($CFG->dirroot . $library);}
use local_tomb\local\{audit, config};
[$options, $unknown] = cli_get_params(['create' => false], []);
if (!$options['create'] || $unknown || config::mode() !== 'rehearsal') {cli_error('Use --create after the dedicated Tomb rehearsal fixture');}
$users = [];
foreach (['learner', 'other', 'teacher'] as $key) {
    $users[$key] = $DB->get_record('user', ['username' => 'tomb.alpha.' . $key, 'idnumber' => 'tomb-alpha-fixture-' . $key], '*', MUST_EXIST);
    if (!config::allowed((int)$users[$key]->id)) {cli_error('Demo users must belong to the rehearsal cohort');}
}
core\session\manager::set_user(get_admin());
$course = $DB->get_record('course', ['idnumber' => 'tomb-demo-03']);
if (!$course) {
    $course = create_course((object)['category' => $DB->get_field_sql('SELECT MIN(id) FROM {course_categories}'),
        'fullname' => 'データで考える、わたしたちの暮らし', 'shortname' => 'DATA & DAILY LIFE', 'idnumber' => 'tomb-demo-03',
        'format' => 'topics', 'numsections' => 3, 'summary' => '<p>身近な問いから始める、データとの付き合い方。架空の授業と学習記録を用いたTombのデモです。</p>',
        'summaryformat' => FORMAT_HTML, 'visible' => 1, 'showgrades' => 1, 'enablecompletion' => 0,
        'startdate' => time() - 86400 * 60, 'enddate' => 0]);
}
$plugin = enrol_get_plugin('manual');
$enrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
if (!$enrol) {
    $enrolid = $plugin->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED]);
    $enrol = $DB->get_record('enrol', ['id' => $enrolid], '*', MUST_EXIST);
}
foreach ($users as $key => $user) {
    $role = $DB->get_record('role', ['shortname' => $key === 'teacher' ? 'editingteacher' : 'student'], '*', MUST_EXIST);
    if (!$DB->record_exists('user_enrolments', ['enrolid' => $enrol->id, 'userid' => $user->id])) {
        $plugin->enrol_user($enrol, $user->id, $role->id);
    }
}
function tomb_demo_module(string $key, string $type, string $name, int $section, array $extra): object {
    global $DB, $course;
    $cm = $DB->get_record('course_modules', ['course' => $course->id, 'idnumber' => 'tomb-demo-' . $key]);
    if ($cm) {return get_coursemodule_from_id($type, $cm->id, $course->id, false, MUST_EXIST);}
    $base = ['modulename' => $type, 'module' => $DB->get_field('modules', 'id', ['name' => $type]),
        'name' => $name, 'section' => $section, 'cmidnumber' => 'tomb-demo-' . $key, 'visible' => 1, 'groupmode' => 0,
        'groupingid' => 0, 'intro' => '', 'introformat' => FORMAT_HTML, 'completion' => 0, 'gradecat' => 0];
    $info = add_moduleinfo((object)array_merge($base, $extra), $course);
    return get_coursemodule_from_id($type, $info->coursemodule, $course->id, false, MUST_EXIST);
}
function tomb_demo_file(object $cm, string $component, string $area, int $item, string $name, string $content, int $userid): void {
    $fs = get_file_storage(); $context = context_module::instance($cm->id);
    if (!$fs->file_exists($context->id, $component, $area, $item, '/', $name)) {
        $fs->create_file_from_string(['contextid' => $context->id, 'component' => $component, 'filearea' => $area,
            'itemid' => $item, 'filepath' => '/', 'filename' => $name, 'userid' => $userid], $content);
    }
}
$welcome = tomb_demo_module('welcome', 'label', '問いを持って、数字の向こうへ', 0,
    ['intro' => '<h3>その平均は、どんな毎日を表している？</h3><p>通学時間、買い物、天気。身近なデータを読み、気づきを言葉にする3つのステップを学びます。</p>']);
$page = tomb_demo_module('page', 'page', '01 平均だけでは見えないこと', 1, ['display' => 0, 'printintro' => 0,
    'printlastmodified' => 0, 'contentformat' => FORMAT_HTML, 'content' =>
    '<h2>同じ平均、違う暮らし</h2><p>A地区とB地区の通学時間を比べます。どちらも平均は30分ですが、毎日の体験は同じでしょうか。</p>' .
    '<img alt="A地区は25・30・35分、B地区は10・30・50分。同じ平均でも広がりが違います。" src="@@PLUGINFILE@@/commute.svg">' .
    '<h3>ばらつきを数字で表す</h3><p>平均は \\(\\bar{x}=\\frac{1}{n}\\sum_{i=1}^{n}x_i\\)、分散は \\(s^2=\\frac{1}{n}\\sum_{i=1}^{n}(x_i-\\bar{x})^2\\) です。</p>' .
    '<p>数値を一つにまとめる前に、分布や生活の背景も確かめましょう。</p>']);
tomb_demo_file($page, 'mod_page', 'content', 0, 'commute.svg',
    '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="280" viewBox="0 0 800 280"><rect width="800" height="280" rx="20" fill="#edf3ef"/>' .
    '<g font-family="sans-serif" fill="#254642"><text x="40" y="45" font-size="22">COMMUTE TIME / MINUTES</text>' .
    '<text x="42" y="105" font-size="20">A</text><text x="42" y="190" font-size="20">B</text>' .
    '<path d="M100 100H720M100 185H720" stroke="#a7bdb3" stroke-width="2"/>' .
    '<g fill="#208474"><circle cx="350" cy="100" r="12"/><circle cx="410" cy="100" r="12"/><circle cx="470" cy="100" r="12"/></g>' .
    '<g fill="#d89543"><circle cx="170" cy="185" r="12"/><circle cx="410" cy="185" r="12"/><circle cx="650" cy="185" r="12"/></g>' .
    '<text x="338" y="135">25</text><text x="398" y="135">30</text><text x="458" y="135">35</text>' .
    '<text x="158" y="220">10</text><text x="398" y="220">30</text><text x="638" y="220">50</text></g></svg>', (int)$users['teacher']->id);
$folder = tomb_demo_module('folder', 'folder', '演習データと読み方', 1, ['files' => 0, 'display' => 0,
    'intro' => '<p>自分の手元で計算し直せるよう、授業で使った小さなデータを残します。</p>']);
tomb_demo_file($folder, 'mod_folder', 'content', 0, 'commute.csv', "area,minutes\nA,25\nA,30\nA,35\nB,10\nB,30\nB,50\n", (int)$users['teacher']->id);
tomb_demo_file($folder, 'mod_folder', 'content', 0, 'README.txt', "授業用の架空データです。areaは地区、minutesは通学時間（分）を表します。\n", (int)$users['teacher']->id);
$assigncm = tomb_demo_module('assignment', 'assign', '02 データから伝える、わたしの考察', 2,
    ['intro' => '<p>平均とばらつきを使って、A地区とB地区の違いを説明してください。数字だけでは判断できないことも一つ挙げましょう。</p>',
        'allowsubmissionsfromdate' => 0, 'duedate' => 0, 'cutoffdate' => 0, 'gradingduedate' => 0, 'alwaysshowdescription' => 1,
        'submissiondrafts' => 0, 'requiresubmissionstatement' => 0, 'sendnotifications' => 0, 'sendlatenotifications' => 0,
        'grade' => 100, 'teamsubmission' => 0, 'requireallteammemberssubmit' => 0, 'blindmarking' => 0,
        'markingworkflow' => 0, 'markingallocation' => 0, 'attemptreopenmethod' => 'none', 'maxattempts' => -1,
        'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 1, 'assignsubmission_file_maxfiles' => 3,
        'assignsubmission_file_maxsizebytes' => 1048576, 'assignfeedback_comments_enabled' => 1, 'assignfeedback_file_enabled' => 1]);
$assignment = new assign(context_module::instance($assigncm->id), $assigncm, $course);
foreach (['learner', 'other'] as $key) {
    $user = $users[$key];
    core\session\manager::set_user($user);
    if (!$assignment->get_user_submission($user->id, false)) {
        $text = $key === 'learner' ? '<p>平均はどちらも30分ですが、A地区の通学時間は平均の近くに集まっています。B地区では10分から50分まで開きがあります。</p>' .
            '<p>分散を計算すると、A地区は約16.7、B地区は約266.7です。「平均30分」という説明だけでは、この違いを伝えられません。</p>' .
            '<p>ただし、交通手段や天候を調べていないため、時間の違いの原因までは断定できません。次は移動手段も記録したいです。</p>' :
            '<p>グラフを描くとB地区の広がりがよく分かりました。中央値も30分なので、散らばりを併せて示すことが大切だと考えました。</p>';
        $data = (object)['onlinetext_editor' => ['text' => $text, 'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid()],
            'files_filemanager' => 0];
        $notices = []; $assignment->save_submission($data, $notices);
        $submission = $assignment->get_user_submission($user->id, false);
        tomb_demo_file($assigncm, 'assignsubmission_file', 'submission_files', $submission->id, '考察ノート.txt', strip_tags($text), (int)$user->id);
    }
    core\session\manager::set_user($users['teacher']);
    if (!$assignment->get_user_grade($user->id, false)) {
        $assignment->save_grade($user->id, (object)['grade' => $key === 'learner' ? 94 : 90, 'attemptnumber' => -1,
            'addattempt' => 0, 'sendstudentnotifications' => 0, 'assignfeedbackcomments_editor' => [
                'text' => '<p>数字を比較したうえで、判断できることと追加調査が必要なことを分けて説明できています。</p><p>次はヒストグラムを添え、読み手が違いを直感的に捉えられるよう工夫してみましょう。</p>',
                'format' => FORMAT_HTML], 'files_filemanager' => 0]);
    }
}
core\session\manager::set_user(get_admin());
$forum = tomb_demo_module('forum', 'forum', '03 気づきを持ち寄る', 2, ['type' => 'general', 'forcesubscribe' => FORUM_DISALLOWSUBSCRIBE,
    'assessed' => 0, 'scale' => 0, 'grade_forum' => 0, 'intro' => '<p>データを見て考えたことを、互いの視点につなげましょう。</p>']);
if (!$DB->record_exists('forum_discussions', ['forum' => $forum->instance])) {
    core\session\manager::set_user($users['other']);
    $id = forum_add_discussion((object)['forum' => $forum->instance, 'course' => $course->id, 'name' => '平均が同じでも、伝えたいことは違う',
        'message' => '<p>地図と重ねたら、通学時間の理由も見えてきそうです。データを集める前に「何を知りたいか」を考えることが大切だと思いました。</p>',
        'messageformat' => FORMAT_HTML, 'messagetrust' => 0, 'mailnow' => 0, 'groupid' => -1, 'timestart' => 0, 'timeend' => 0, 'timelocked' => 0]);
    $discussion = $DB->get_record('forum_discussions', ['id' => $id], '*', MUST_EXIST);
    core\session\manager::set_user($users['learner']);
    forum_add_new_post((object)['discussion' => $id, 'parent' => $discussion->firstpost, 'subject' => '暮らしの背景も合わせて考えたい',
        'message' => '<p>私も、数字の背景を調べたいです。次の調査では、徒歩・自転車・バスを分けて記録してみませんか。</p>',
        'messageformat' => FORMAT_HTML, 'messagetrust' => 0, 'itemid' => 0, 'mailnow' => 0], null);
}
core\session\manager::set_user(get_admin());
$quizoptions = ['timeopen' => 0, 'timeclose' => 0, 'preferredbehaviour' => 'deferredfeedback', 'attempts' => 0,
    'attemptonlast' => 0, 'grademethod' => QUIZ_GRADEHIGHEST, 'decimalpoints' => 2, 'questiondecimalpoints' => -1,
    'questionsperpage' => 1, 'shuffleanswers' => 1, 'sumgrades' => 0, 'grade' => 100, 'timelimit' => 0,
    'overduehandling' => 'autosubmit', 'graceperiod' => 86400, 'quizpassword' => '', 'subnet' => '', 'browsersecurity' => '',
    'delay1' => 0, 'delay2' => 0, 'showuserpicture' => 0, 'showblocks' => 0, 'navmethod' => QUIZ_NAVMETHOD_FREE,
    'intro' => '<p>学んだことを、保存済みの回答とともに振り返りましょう。既存の検証用問題から基本問題を選んでいます。</p>'];
foreach (['during', 'immediately', 'open', 'closed'] as $when) {
    foreach (['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback'] as $field) {
        $quizoptions[$field . $when] = 1;
    }
}
$quiz = tomb_demo_module('quiz', 'quiz', '04 理解を確かめ、次の問いへ', 3, $quizoptions);
if (!$DB->record_exists('quiz_slots', ['quizid' => $quiz->instance])) {
    $record = $DB->get_record('quiz', ['id' => $quiz->instance], '*', MUST_EXIST);
    foreach (['multichoice', 'truefalse', 'numerical'] as $type) {
        $questions = $DB->get_records_sql('SELECT q.id FROM {question} q JOIN {question_versions} v ON v.questionid = q.id
            WHERE q.qtype = ? AND v.status = ? ORDER BY q.id', [$type, 'ready'], 0, 1);
        if ($questions) {quiz_add_quiz_question((int)array_key_first($questions), $record);}
    }
    \mod_quiz\quiz_settings::create($quiz->instance)->get_grade_calculator()->recompute_quiz_sumgrades();
}
rebuild_course_cache($course->id, true);
core\session\manager::set_user($users['learner']);
if (!$DB->record_exists('quiz_attempts', ['quiz' => $quiz->instance, 'userid' => $users['learner']->id])) {
    $settings = \mod_quiz\quiz_settings::create($quiz->instance, $users['learner']->id);
    $attempt = quiz_prepare_and_start_new_attempt($settings, 1, null);
    $object = \mod_quiz\quiz_attempt::create($attempt->id);
    $responses = [];
    foreach ($object->get_slots() as $slot) {
        $qa = $object->get_question_attempt($slot);
        $responses[$qa->get_control_field_name('sequencecheck')] = $qa->get_sequence_check_count();
        foreach ($qa->get_correct_response() as $name => $value) {$responses[$qa->get_qt_field_name($name)] = $value;}
    }
    $object->process_submitted_actions(time(), false, $responses);
    $object->process_submit(time(), false);
    $object->process_grade_submission(time());
}
core\session\manager::set_user(get_admin());
set_config('democourseid', $course->id, 'local_tomb');
audit::add('demo_prepared', 0, ['courseid' => $course->id]);
cli_writeln('Presentation course ready: ' . $course->id . '. Original regression fixtures are retained.');
