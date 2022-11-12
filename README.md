# StopSurveyEval - Redcap External Module

## What does it do?

Redcap projects with 100+ events and many ASI enabled surveys can become slow to save (30+ seconds). This EM prevents (almost) all ASI logic from evaluating on a form/survey save to improve load times. Surveys marked as "Send immediately" always have their logic evaluated. Logic evaluation can be offloaded to a cron or can occur only when certain events are saved, but configurable per project.

Note: This EM doesn't stop survey evaluation from occuring on Data Import, in cron jobs, other EM etc, it only impacts saving on a form or survey.

## Installing

You can install the module from the REDCap EM repo or drop it directly in your modules folder (i.e. `redcap/modules/stop_survey_eval_v1.0.0`).
