<?php

    public function checkToScheduleParticipantInvitation($record, $isNewRecord = false, $surveysEvents = array())
    {
        $this->setSchedules();
        foreach ($this->schedules as $survey_id => $events) {
            foreach ($events as $event_id => $attr) {
                $surveysEvents[$survey_id][$event_id] = null;
                if ($attr['condition_send_time_option'] == 'IMMEDIATELY' || in_array($event_id, [{{ event_list }}])) {
                    $surveysEvents[$survey_id][$event_id] = true;
                }
            }
        }
        return $this->_checkToScheduleParticipantInvitation($record, $isNewRecord, $surveysEvents);
    }
