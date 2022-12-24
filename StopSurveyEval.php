<?php

namespace UWMadison\StopSurveyEval;

use ExternalModules\AbstractExternalModule;
use SurveyScheduler;

class StopSurveyEval extends AbstractExternalModule
{
    private $classFile = "../../redcap_v" . REDCAP_VERSION . "/Classes/SurveyScheduler.php";

    public function redcap_save_record($project_id, $record, $instrument, $event_id)
    {
        // Fetch code as strings
        $code = $this->fetchCode($this->classFile);
        $newMethod = $this->fetchCode($this->getModulePath() . "patch.php");

        // Check user config
        $includeEvents = $this->getProjectSetting("current") ? [$event_id] : [];
        $includeEvents = array_merge($includeEvents, $this->getProjectSetting("events") ?? []);
        if ($this->getProjectSetting('cron')) {
            $list = json_decode($this->getProjectSetting('records'), true);
            $list[] = $record;
            $this->setProjectSetting('records', json_encode(array_unique($list)));
        }

        // Update and insert new code
        $newMethod = str_replace("{{ event_list }}", implode(", ", $includeEvents), $newMethod);
        $newCode = $this->redefineFunction($code, $newMethod);
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
            $surveyScheduler->checkToScheduleParticipantInvitation($id);
            $tmp = $this->getRecords($pid);
            $this->setProjectSetting('records', json_encode(array_diff($tmp, $id)), $pid);

            // If we have run for over a minute, exit so we visit other projects
            if ((time() - $start) > 60) break;
        }

        return $exitMsg;
    }
}
