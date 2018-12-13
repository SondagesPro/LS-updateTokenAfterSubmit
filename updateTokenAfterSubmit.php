<?php
/**
 * Update (and reset if needed) token code after submission
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2018 Denis Chenu <http://www.sondages.pro>
 * @license GPL v3
 * @version 0.3.2
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */
class updateTokenAfterSubmit extends PluginBase {

    static protected $description = 'Update (and reset if needed) token code after submission';
    static protected $name = 'updateTokenAfterSubmit';

    protected $storage = 'DbStorage';

    public function init() {
        $this->subscribe('afterSurveyComplete','afterSurveyComplete');
        $this->subscribe('afterSurveyQuota','afterSurveyQuota');
        /* Survey settings */
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
    }

    public function afterSurveyComplete() {
        if($this->updateToken()) {
            if($this->get('autoRedirect','Survey',$surveyId,1)) {
                $surveyId = $this->getEvent()->get('surveyId');
                $language = Yii::app()->getLanguage();
                $oSurveyLanguageSettings = SurveyLanguageSetting::model()->findByPk(array('surveyls_survey_id'=>$surveyId, 'surveyls_language'=>$language));
                if(!empty($oSurveyLanguageSettings) && !empty($oSurveyLanguageSettings->surveyls_url)) {
                    header("Location: {$oSurveyLanguageSettings->surveyls_url}");
                }
            }
        }
    }

    public function afterSurveyQuota() {
        $this->updateToken();
    }

    /**
     * update token and response
     * @return boolean if action is done (with or without success)
     */
    public function updateToken() {
        $surveyId = $this->getEvent()->get('surveyId');
        $responseId = $this->getEvent()->get('responseId');
        if(empty($responseId)) {
            return;
        }
        if(!$this->_surveySettingsAllowUsage($surveyId)) {
            return;
        }
        $fixedCode = $this->get('fixedCode','Survey',$surveyId,"");
        if(trim($fixedCode) === "") {
            return;
        }
        $oSurvey = Survey::model()->findByPk($surveyId);
        if(intval(Yii::app()->getConfig('versionnumber')) >= 3) {
            $hasTokenTable = $oSurvey->getHasTokensTable();
        } else {
            $hasTokenTable = Survey::model()->hasTokens($surveyId);
        }
        if(!$hasTokenTable) {
            return;
        }

        $resetTokenResponse = $this->get('resetTokenResponse','Survey',$surveyId,"");
        if(empty($resetTokenResponse)) {
            if($oSurvey->alloweditaftercompletion == "Y") {
                $resetTokenResponse = "N";
            } else {
                $resetTokenResponse = "Y";
            }
        }
        $bResetTokenResponse = $resetTokenResponse == "Y";

        $oResponse = Response::model($surveyId)->findByPk($responseId);
        if(empty($oResponse) || empty($oResponse->token)) {
            return;
        }
        $token = $oResponse->token;

        if(substr($token, -strlen($fixedCode)) === $fixedCode){
            return;
        }
        $oToken = Token::model($surveyId)->find("token = :token",array(":token" => $token));
        if(empty($oToken)) {
            $this->log("Token code {$token} not found in survey {$surveyId}",'error');
            return;
        }
        $oToken->token = $token.$fixedCode;

        if($bResetTokenResponse) {
            $oToken->completed = "N";
            $oToken->usesleft++;
        }
        if($this->get('keepTrackOn','Survey',$surveyId,"")) {
            $keepTrackOn = $this->get('keepTrackOn','Survey',$surveyId,"");
            $currentTrack = isset($oToken->$keepTrackOn) ? $oToken->$keepTrackOn : null;
            if(!is_null($currentTrack)) {
                $numberTrack = 0;
                if(substr($currentTrack, 0, strlen("updateTokenAfterSubmit-")) === "updateTokenAfterSubmit-") {
                    $numberTrack = intval(substr($currentTrack,strlen("updateTokenAfterSubmit-")));
                }
                $numberTrack++;
                $oToken->$keepTrackOn = "updateTokenAfterSubmit-".$numberTrack;
            }
        }
        if($this->get('emailUpdatedTo','Survey',$surveyId,"")) {
            $emailUpdatedTo = $this->get('emailUpdatedTo','Survey',$surveyId,"");
            if(intval(Yii::app()->getConfig('versionnumber')) >= 3) {
                $emailUpdatedTo = LimeExpressionManager::ProcessStepString($emailUpdatedTo,array(),3,true); // as static
            } else {
                $emailUpdatedTo = LimeExpressionManager::ProcessString($emailUpdatedTo,null, array(), false, 3, 1, false, false, true);
            }
            $oToken->scenario = 'allowinvalidemail'; // Need diable rule
            $oToken->email = $emailUpdatedTo;
        }
        if(!$oToken->save()) {
            $this->log("Unable to update token code {$token} in survey {$surveyId}",'error');
            $this->log(sprintf("With error %",CVarDumper::dumpAsString($oToken->getErrors(), 2, false)),'warning');
        } else {
            $this->log("Update token code {$token} in survey {$surveyId} with success",'info');
        }

        if($bResetTokenResponse) {
            $oResponse->submitdate = null;
            $oResponse->lastpage = 1;
        }
        $oResponse->token = $token.$fixedCode;
        if(!$oResponse->save()) {
            $this->log("Unable to update response {$responseId} in survey {$surveyId}",'error');
            $this->log(sprintf("With error %",CVarDumper::dumpAsString($oResponse->getErrors(), 2, false)),'warning');
        } else {
            $this->log("Update response {$responseId} in survey {$surveyId} with success",'info');
        }
        return true;
    }

    /** @inheritdoc **/
    public function beforeSurveySettings()
    {
        $oEvent = $this->event;
        $surveyId = $oEvent->get('survey');
        $oSurvey = Survey::model()->findByPk($surveyId);
        $aSettings = array();
        if(!$this->_surveySettingsAllowUsage($surveyId)) {
            $aSettings['warning']= array(
                'type' => 'info',
                'content' => "<div class='alert alert-warning warning'>".$this->_translate("Curent settings disallow usage of this plugin: anonymized responses and token-based response persistence must be on")."</div>",
            );
        } else {
            if($oSurvey->autoredirect == "Y") {
                $aSettings['warning']= array(
                    'type' => 'info',
                    'content' => "<div class='alert alert-warning warning'>".$this->_translate("Automatically load URL when survey complete is activated, this disable usage of this plugin.")."</div>",
                );
            }
        }
        $aSettings = array_merge($aSettings,array(
            'fixedCode' => array(
                'type' => 'string',
                'label' => $this->_translate("Fixed code to add when survey is submitted."),
                'help' => $this->_translate("This code is added at the end of the token only if it does not end already with this code."),
                'current' => $this->get('fixedCode','Survey',$surveyId,"")
            ),
            'keepTrackOn' => array(
                'type' => 'select',
                'options' => $this->_getTokensAttributeList($surveyId),
                'htmlOptions' => array(
                    'empty' => $this->_translate("No tracking on token table"),
                ),
                'label' => $this->_translate("Where to keep track information about updated token."),
                'help' => $this->_translate("Data for this token is updated to updateTokenAfterSubmit-X, where X is the number of updating done."),
                'current'=>$this->get('keepTrackOn','Survey',$surveyId,""),
            ),
            'resetTokenResponse' => array(
                'type' => 'select',
                'options' => array(
                    'Y'=>gT("Yes"),
                    'N'=>gT("No"),
                ),
                'htmlOptions' => array(
                    'empty' => $this->_translate("Automatic"),
                ),
                'label' => $this->_translate("Reset token and response to allow editing response. "),
                'help' => $this->_translate("Token is reset to not completed and response to not submitted.Automatic mean do it if survey don‘t allow edit after completion."),
                'current'=>$this->get('resetTokenResponse','Survey',$surveyId,""),
            ),
            'emailUpdatedTo' => array(
                'type' => 'string',
                'label' => $this->_translate("Update email with new value."),
                'help' => $this->_translate("Usage of expression is allowed and invalid email is saved too."),
                'current'=>$this->get('emailUpdatedTo','Survey',$surveyId,""),
            ),
            'autoRedirect' => array(
                'type' => 'boolean',
                'label' => $this->_translate("Automatically load URL after this plugin action."),
                'help' => $this->_translate("You can‘t use automatically load URL when survey complete from limesurvey core, then if needed activate it here. Warning : no replacement is done on the url. This redirection is done only if token are updated."),
                'current'=>$this->get('autoRedirect','Survey',$surveyId,0),
            ),
        ));
        $oEvent->set("surveysettings.{$this->id}", array(
            'name' => get_class($this),
            'settings' => $aSettings
        ));
    }

    /** @inheritdoc **/
    public function newSurveySettings()
    {
        $event = $this->getEvent();
        foreach ($event->get('settings') as $name => $value) {
          $this->set($name, $value, 'Survey', $event->get('survey'));
        }
    }

    /**
     * get the token attribute in listData
     * @param integer $surveyId
     * @param string $prefix
     * @return array
     */
    private function _getTokensAttributeList($surveyId,$prefix="") {
        $oSurvey = Survey::model()->findByPk($surveyId);
        $aTokenAttributes = array(
            $prefix.'firstname'=>gT("First name"),
            $prefix.'lastname'=>gT("Last name"),
            $prefix.'email'=>gT("Email"),
            $prefix.'emailstatus'=>gT("Email status"),
        );
        foreach($oSurvey->getTokenAttributes() as $attribute=>$information) {
            $aTokenAttributes[$prefix.$attribute] = empty($information['description']) ? $attribute : $information['description'];
        }
        return $aTokenAttributes;
    }

    /**
     * Own translation using parent::gT
     * @see parent::gT
     */
    public function _translate($string, $sEscapeMode = 'unescaped', $sLanguage = NULL) {
        if(intval(Yii::app()->getConfig('versionnumber')) >= 3) {
            return parent::gT($string, $sEscapeMode, $sLanguage );
        }
        return $string;
    }

    /**
    * @inheritdoc adding string, by default current event
    * @param string
    */
    public function log($message, $level = \CLogger::LEVEL_TRACE,$logDetail = null)
    {
        if(!$logDetail && $this->getEvent()) {
            $logDetail = $this->getEvent()->getEventName();
        }
        if(empty($logDetail)) {
            $logDetail = "unknow";
        }
        if(intval(Yii::app()->getConfig('versionnumber')) >= 3) {
            parent::log($message, $level);
        }
        Yii::log($message, $level,'application.plugins.updateTokenAfterSubmit.'.$logDetail);
    }

    /**
     * Find if survey settings allow usage of this plugin
     * @param integer $surveyId
     * return boolean
     */
    private function _surveySettingsAllowUsage($surveyId)
    {
        $oSurvey = Survey::model()->findByPk($surveyId);
        return ($oSurvey->anonymized !="Y" && $oSurvey->tokenanswerspersistence =="Y");
    }
}
