<?php
/**
 * Update (and reset if needed) token code after submission
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2018 Denis Chenu <http://www.sondages.pro>
 * @license GPL v3
 * @version 0.0.1
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
        $this->subscribe('afterSurveyComplete','updateToken');
        $this->subscribe('afterSurveyQuota','updateToken');
        /* Survey settings */
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
    }

    public function updateToken() {
        $surveyId = $this->getEvent()->get('surveyId');
        $responseId = $this->getEvent()->get('responseId');
        if(empty($responseId)) {
            return;
        }
        $fixedCode = $this->get('fixedCode','Survey',$surveyId,"");
        if(trim($fixedCode) === "") {
            return;
        }
        $oSurvey = Survey::model()->findByPk($surveyId);
        if(!$oSurvey->getHasTokensTable()) {
            return;
        }
        $token = isset($_SESSION['survey_'.$surveyId]['token']) ? $_SESSION['survey_'.$surveyId]['token']: null;
        if(empty($token)) {
            return;
        }
        if(substr($token, -strlen($fixedCode)) === $fixedCode){
            return;
        }
        $oToken = Token::model($surveyId)->find("token = :token",array(":token" => $token));
        if(empty($oToken)) {
            $this->log("Token code {$token} not found in survey {$surveyId}",'error');
            return;
        }
        $oToken->token = $token.$fixedCode;
        if($this->get('resetToken','Survey',$surveyId,1)) {
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
        if(!$oToken->save()) {
            $this->log("Unable to update token code {$token} in survey {$surveyId}",'error');
            $this->log(sprintf("With error %",CVarDumper::dumpAsString($oToken->getErrors(), 2, false)),'warning');
        } else {
            $this->log("Update token code {$token} in survey {$surveyId} with success",'info');
        }
        if($this->get('resetToken','Survey',$surveyId,1)) {
            $oResponse = Response::model($surveyId)->findByPk($responseId);
            $oResponse->submitdate = null;
            if(!$oResponse->save()) {
                $this->log("Unable to update response {$responseId} in survey {$surveyId}",'error');
                $this->log(sprintf("With error %",CVarDumper::dumpAsString($oResponse->getErrors(), 2, false)),'warning');
            } else {
                $this->log("Update response {$responseId} in survey {$surveyId} with success",'info');
            }
        }
    }

    /** @inheritdoc **/
    public function beforeSurveySettings()
    {
        $oEvent = $this->event;
        $surveyId = $oEvent->get('survey');
        $oEvent->set("surveysettings.{$this->id}", array(
            'name' => get_class($this),
            'settings' => array(
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
                'resetToken' => array(
                    'type' => 'boolean',
                    'label' => $this->_translate("Reset token and response to allow editing response."),
                    'help' => $this->_translate("If needed : token is reset to not completed and response to not submitted."),
                    'current'=>$this->get('resetToken','Survey',$surveyId,1),
                ),
            )
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
        } // What to put if no event ?
        if(intval(Yii::app()->getConfig('versionnumber')) >= 3) {
            parent::log($message, $level);
        }
        Yii::log($message, $level,'application.plugins.reloadAnyResponse.'.$logDetail);
    }
}
