<?php

namespace UWMadison\StopSurveyEval;

use ExternalModules\AbstractExternalModule;
use Project;
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
            $this->setProjectSetting('records', json_encode($list));
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

    public function run_cron($cronInfo)
    {
        // Stash original PID, probably not needed, but docs recommend
        $originalPid = $_GET['pid'];
        global $Proj;

        // Loop over every pid using this EM
        foreach ($this->getProjectsWithModuleEnabled() as $pid) {

            // Act like we are in that project
            $_GET['pid'] = $pid;
            $Proj = new Project($pid);

            // Skip if cron feature not enabled
            if (!$this->getProjectSetting('cron', $pid)) continue;

            // Pull records that need eval and go
            $records = array_unique(json_decode($this->getProjectSetting('records', $pid), true));
            if (empty($records)) continue;
            $surveyScheduler = new SurveyScheduler($pid);
            foreach ($records as $index => $id) {
                $surveyScheduler->checkToScheduleParticipantInvitation($id);
                $this->setProjectSetting('records', json_encode(array_slice($records, $index + 1)), $pid);
            }
        }

        $_GET['pid'] = $originalPid;
        return "The \"{$cronInfo['cron_name']}\" cron job completed successfully.";
    }
}
