<?php
/**
 * Automatic fix online time procedure. If a COURSEMANAGER has been idle for $timeLimit
 * or more then the procedure adds $extraTime to his logout_course_date.
 * @package chamilo.cron
 * @author Imanol Losada <imanol.losada@beeznest.com>
 */
require_once __DIR__ . '/../inc/global.inc.php';

/**
 * Get ids of COURSEMANAGERs that are inside a course right now
 * @return array COURSEMANAGER's ids
 */
function getTeachersInCourseIds()
{
    $table = Database::get_statistic_table(TABLE_STATISTIC_TRACK_E_ONLINE);
    $joinStatement = ' JOIN ' . Database::get_main_table(TABLE_MAIN_USER) . ' ON login_user_id = user_id';
    return Database::select(
        'login_user_id', $table . $joinStatement,
        array(
            'where' => array(
                'course <> ? AND status = ?' => array(
                    'NULL',
                    COURSEMANAGER
                )
            )
        )
    );
}

/**
 * If a COURSEMANAGER has been idle for $timeLimit or more then
 * the procedure adds $extraTime to his logout_course_date.
 * @param array COURSEMANAGER's ids
 * @return void
 */
function updateTeachersInCourseIdleForTimeLimit($teachersInCourseIds)
{
    $timeLimit = '- 30 minute';
    $extraTime = '+ 5 minute';
    $utcResult = Database::fetch_array(
        Database::query('SELECT UTC_TIMESTAMP')
    );
    $dataBaseCurrentHour = array_shift($utcResult);
    $maximumIdleTimeInCourse = date(
        'Y-m-d H:i:s',
        strtotime($dataBaseCurrentHour . ' ' . $timeLimit)
    );
    $table = Database::get_statistic_table(TABLE_STATISTIC_TRACK_E_COURSE_ACCESS);
    foreach ($teachersInCourseIds as $key => $value) {
        $value = array_shift($value);
        $logResult = Database::select(
            'course_access_id,logout_course_date',
            $table,
            array(
                'where' => array(
                    'user_id = ?' => array(
                        $value,
                    )
                ),
                'order' => 'course_access_id DESC',
                'limit' => '1'
            )
        );
        $currentTeacherData = array_shift($logResult);
        Database::update(
            $table,
            array(
                'logout_course_date' => date(
                    'Y-m-d H:i:s',
                    strtotime($currentTeacherData['logout_course_date'] . ' ' . $extraTime)
                )
            ),
            array(
                'user_id = ? AND logout_course_date < ? AND course_access_id = ?' => array(
                    $value,
                    $maximumIdleTimeInCourse,
                    $currentTeacherData['course_access_id']
                )
            )
        );
        Database::update(
            Database::get_statistic_table(TABLE_STATISTIC_TRACK_E_ONLINE),
            array(
                'course' => 'NULL'
            )
        );
    }
}

/**
 * Initialization
 */
if (php_sapi_name() != 'cli') {
    exit; //do not run from browser
}
$teachersInCourseIds = getTeachersInCourseIds();
if (!empty($teachersInCourseIds)) {
    updateTeachersInCourseIdleForTimeLimit($teachersInCourseIds);
}