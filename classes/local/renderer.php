<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;
defined('MOODLE_INTERNAL') || die();

final class renderer {
    public static function page(object $request, string $path, string $title, string $body, string $eyebrow = ''): string {
        global $CFG;
        $e = [paths::class, 'escape'];
        $root = paths::relative($path, 'index.html');
        $css = paths::relative($path, '_assets/archive.css');
        $omissions = paths::relative($path, 'omissions.html');
        $mathconfig = paths::relative($path, '_assets/math-config.js');
        $math = paths::relative($path, '_assets/mathjax/tex-mml-chtml.js');
        $mathscripts = is_file($CFG->dirroot . '/local/tomb/assets/mathjax/tex-mml-chtml.js') ?
            '<script src="' . $mathconfig . '"></script><script defer src="' . $math . '"></script>' : '';
        $name = fullname(\core_user::get_user($request->subjectid));
        return '<!doctype html><html lang="' . i18n::language($request->outputlang ?? 'ja') . '"><head><meta charset="utf-8"><meta name="viewport" ' .
            'content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer">' .
            '<meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; ' .
            'script-src &#39;self&#39;; style-src &#39;self&#39; &#39;unsafe-inline&#39;; ' .
            'img-src &#39;self&#39; data:; font-src &#39;self&#39;; media-src &#39;self&#39;; connect-src &#39;none&#39;">' .
            '<title>' . $e($title) . ' · Tomb</title><link rel="stylesheet" href="' . $css . '">' .
            $mathscripts . '</head><body><header class="topbar"><a class="brand" href="' . $root .
            '"><span class="brand-mark">T</span>tomb<span class="brand-caption">LEARNING ARCHIVE</span></a>' .
            i18n::get('text_available_offline_dd15b1') .
            '<aside><p class="side-label">MY LEARNING</p><p class="owner">' . $e($name) . '</p>' .
            '<nav><a href="' . $root . i18n::get('text_learning_archive_home_a_href_0f561c') . $omissions .
            i18n::get('text_about_this_archive_what_you_9b4290') .
            '<article><div class="eyebrow">' . $e($eyebrow ?: 'YOUR LEARNING, PRESERVED') . '</div><h1>' .
            $e($title) . '</h1>' . $body . i18n::get('text_tomb_learning_archive_source_a48ad8') .
            $e($CFG->wwwroot) . i18n::get('text_collection_period_c7d705') . $e(userdate($request->timestarted)) . i18n::get('text_fragment_ea4317') .
            $e(userdate($request->timecollected)) . i18n::get('text_generated_d94259') . $e(userdate($request->timefinished)) .
            i18n::get('text_policy_2ac2f0') . $e($request->policy) . i18n::get('text_a_href_975c5b') . $omissions .
            i18n::get('text_coverage_and_archive_notes_968920');
    }
}
