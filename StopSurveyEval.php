<?php

namespace UWMadison\StopSurveyEval;

use ExternalModules\AbstractExternalModule;
use SurveyScheduler;

class StopSurveyEval extends AbstractExternalModule
{
    private $classFile = "../../redcap_v" . REDCAP_VERSION . "/Classes/SurveyScheduler.php";
    private $patch = '
        public function checkToScheduleParticipantInvitation($record, $isNewRecord = false, $surveysEvents = [])
        {
            $this->setSchedules();
            foreach ($this->schedules as $survey_id => $events) {
                foreach ($events as $event_id => $attr) {
                    $surveysEvents[$survey_id][$event_id] = null;
                    if ($attr["condition_send_time_option"] == "IMMEDIATELY" || in_array($event_id, [{{ event_list }}])) {
                        $surveysEvents[$survey_id][$event_id] = true;
                    }
                }
            }
            return $this->_checkToScheduleParticipantInvitation($record, $isNewRecord, $surveysEvents);
        }
    ';

    public function redcap_save_record($project_id, $record, $instrument, $event_id)
    {
        // Check user config
        $includeEvents = $this->getProjectSetting("current") ? [$event_id] : [];
        $includeEvents = array_merge($includeEvents, $this->getProjectSetting("events") ?? []);

        // Update Cron if enabled
        if ($this->getProjectSetting('cron')) {
            $list = json_decode($this->getProjectSetting('records'), true);
            $list[] = $record;
            $this->setProjectSetting('records', json_encode($list));
        }

        // Update and insert new code
        $newMethod = str_replace("{{ event_list }}", implode(", ", $includeEvents), $this->patch);
        $newCode = $this->redefineFunction($this->fetchCode($this->classFile), $newMethod);
        eval($newCode);
    }

    private function fetchCode($path)
    {
        $fp = fopen($path, 'r');
        $code = fread($fp, filesize($path));
        $code = str_replace('<?php', '', $code);
        $code = str_replace('?>', '', $code);
        fclose($fp);
        return $code;
    }

    private function redefineFunction($code, $newFn)
    {
        preg_match('/function (.+?)\(/', $newFn, $aryMatches);
        $name = trim($aryMatches[1]);
        if (!preg_match('/((private|protected|public) function ' . $name . '[\w\W\n]+?)(private|protected|public)/s', $code, $aryMatches)) {
            return false;
        }
        $search = $aryMatches[1];
        $oldFn = str_replace($name, "_" . $name, $search);
        return str_replace($search, $newFn . "\n\n" . $oldFn, $code);
    }

    private function getRecords($pid)
    {
        return json_decode($this->getProjectSetting('records', $pid), true);
    }

    public function run_cron($cronInfo)
    {
        $exitMsg = "The \"{$cronInfo['cron_name']}\" cron job completed successfully.";
        $projects = [];

        // Loop over every pid using this EM
        foreach ($this->getProjectsWithModuleEnabled() as $pid) {

            // Skip if cron feature not enabled
            if (!$this->getProjectSetting('cron', $pid)) continue;

            // Pull records that need eval and save the info
            $records = $this->getRecords($pid);
            if (empty($records)) continue;
            $projects[$pid] = $this->getProjectSetting('time', $pid);
        }

        // Find what pid we should work on, update it's time
        if (empty($projects)) return $exitMsg;
        asort($projects);
        $pid = array_keys($projects)[0];
        $this->setProjectSetting('time', time(), $pid);

        // Crunch ASI
        $records = $this->getRecords($pid);
        $surveyScheduler = new SurveyScheduler($pid);
        $start = time();
        foreach ($records as $id) {
            // Normal function, not our custom one
            $surveyScheduler->checkToScheduleParticipantInvitation($id);

            // Re-pull the records, they could have been updated
            $tmp = $this->getRecords($pid);
            $count = array_count_values($tmp)[$id];
            $write = array_diff($tmp, [$id]);
            if ($count > 1) {
                $write[] = $id;
            }
            $this->setProjectSetting('records', json_encode($write), $pid);

            // If we have run for over a minute, exit so we visit other projects
            if ((time() - $start) > 60) break;
        }

        return $exitMsg;
    }
}
