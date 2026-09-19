<?php
// License: GNU GPL v3 or later.
namespace local_tomb\privacy;
defined('MOODLE_INTERNAL') || die();

/** Declares the archive's personal data. Automated Privacy API export/erasure is a post-alpha requirement. */
final class provider implements \core_privacy\local\metadata\provider {
    public static function get_metadata(\core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
        $collection->add_database_table('local_tomb_request', [
            'subjectid' => 'privacy:metadata:subjectid', 'requesterid' => 'privacy:metadata:requesterid',
            'timecreated' => 'privacy:metadata:timecreated', 'reason' => 'privacy:metadata:details',
            'courses' => 'privacy:metadata:details', 'lastdownload' => 'privacy:metadata:timecreated',
            'received' => 'privacy:metadata:timecreated'], 'privacy:metadata:local_tomb_request');
        $collection->add_database_table('local_tomb_audit', [
            'actorid' => 'privacy:metadata:actorid', 'details' => 'privacy:metadata:details',
            'timecreated' => 'privacy:metadata:timecreated'], 'privacy:metadata:local_tomb_audit');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:files');
        return $collection;
    }
}
