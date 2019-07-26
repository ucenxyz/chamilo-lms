<?php
/* For licensing terms, see /license.txt */

use ChamiloSession as Session;

/**
 * @package chamilo.exercise
 *
 * @author Julio Montoya <gugli100@gmail.com>
 */
require_once __DIR__.'/../inc/global.inc.php';

api_protect_course_script();

require_once api_get_path(LIBRARY_PATH).'geometry.lib.php';

$message = null;
$dbg_local = 0;
$gradebook = null;
$final_overlap = null;
$final_missing = null;
$final_excess = null;
$threadhold1 = null;
$threadhold2 = null;
$threadhold3 = null;

$exerciseResult = Session::read('exerciseResult');
$exerciseResultCoordinates = isset($_REQUEST['exerciseResultCoordinates']) ? $_REQUEST['exerciseResultCoordinates'] : null;

$learnpath_id = 0;
if (isset($_REQUEST['learnpath_id'])) {
    $learnpath_id = (int) $_REQUEST['learnpath_id'];
}

$learnpath_item_id = 0;
if (isset($_REQUEST['learnpath_item_id'])) {
    $learnpath_item_id = (int) $_REQUEST['learnpath_item_id'];
}

/** @var Exercise $objExercise */
$objExercise = Session::read('objExercise');

if (empty($objExercise)) {
    api_not_allowed();
}

Session::write('hotspot_coord', []);
$newQuestionList = Session::read('newquestionList', []);
$questionList = Session::read('questionList');
$exerciseId = (int) $_GET['exerciseId'];
$exerciseType = (int) $_GET['exerciseType'];
$questionNum = (int) $_GET['num'];
$nbrQuestions = isset($_GET['nbrQuestions']) ? (int) $_GET['nbrQuestions'] : null;

// Clean extra session variables
Session::erase('objExerciseExtra'.$exerciseId);
Session::erase('exerciseResultExtra'.$exerciseId);
Session::erase('questionListExtra'.$exerciseId);
$choiceValue = isset($_GET['choice']) ? $_GET['choice'] : '';
if (!empty($choiceValue)) {
    $choiceValue = json_decode($choiceValue);
    if (isset($choiceValue->answers)) {
        $choiceValue = $choiceValue->answers;
    }
}

echo '<div id="delineation-container">';
// Getting the options by js
if (empty($choiceValue)) {
    echo "<script>
        // this works for only radio buttons
        var f = window.document.frm_exercise;        
        var choice_js = {answers: []};
        var hotspot = new Array();
        var hotspotcoord = new Array();
        var counter = 0;
        
        for (var i = 0; i < f.elements.length; i++) {            
            if (f.elements[i].type == 'radio' && f.elements[i].checked) {                
                choice_js.answers.push(f.elements[i].value);
                counter ++;
            }
            
            if (f.elements[i].type == 'checkbox' && f.elements[i].checked) {
                choice_js.answers.push(f.elements[i].value);
                counter ++;
            }

            if (f.elements[i].type == 'hidden') {
                var name = f.elements[i].name;
                
                if (name.substr(0,7) == 'hotspot') {
                    hotspot.push(f.elements[i].value);
                }

                if (name.substr(0,20) == 'hotspot_coordinates') {
                    hotspotcoord.push(f.elements[i].value);
                }
            }
        }

        if (counter == 0) {
            choice_js = -1; // this is an error
        } else {
            choice_js = JSON.stringify(choice_js);
        }
    ";
    // IMPORTANT
    // This is the real redirect function
    echo ' url = "exercise_submit_modal.php?learnpath_id='.$learnpath_id.'&learnpath_item_id='.$learnpath_item_id.'&hotspotcoord="+ hotspotcoord + "&hotspot="+ hotspot + "&choice="+ choice_js + "&exerciseId='.$exerciseId.'&num='.$questionNum.'&exerciseType='.$exerciseType.'&'.api_get_cidreq().'&gradebook='.$gradebook.'";';
    echo "$('#global-modal .modal-body').load(url);";
    echo '</script>';
    exit;
}

// Round-up the coordinates
$user_array = '';
if (isset($_GET['hotspot'])) {
    $coords = explode('/', $_GET['hotspot']);
    if (is_array($coords) && count($coords) > 0) {
        foreach ($coords as $coord) {
            if (!empty($coord)) {
                list($x, $y) = explode(';', $coord);
                $user_array .= round($x).';'.round($y).'/';
            }
        }
    }
}

$user_array = substr($user_array, 0, -1);

$choice = [];
$questionId = $questionList[$questionNum];
$choice[$questionId] = isset($choiceValue) ? $choiceValue : null;

if (!is_array($exerciseResult)) {
    $exerciseResult = [];
}

// if the user has answered at least one question
if (is_array($choice)) {
    if (in_array($exerciseType, [EXERCISE_FEEDBACK_TYPE_DIRECT, EXERCISE_FEEDBACK_TYPE_POPUP])) {
        // $exerciseResult receives the content of the form.
        // Each choice of the student is stored into the array $choice
        $exerciseResult = $choice;
    } else {
        // gets the question ID from $choice. It is the key of the array
        list($key) = array_keys($choice);
        // if the user didn't already answer this question
        if (!isset($exerciseResult[$key])) {
            // stores the user answer into the array
            $exerciseResult[$key] = $choice[$key];
        }
    }
}

// the script "exercise_result.php" will take the variable $exerciseResult from the session
Session::write('exerciseResult', $exerciseResult);
Session::write('exerciseResultCoordinates', $exerciseResultCoordinates);

// creates a temporary Question object
if (in_array($questionId, $questionList)) {
    $objQuestionTmp = Question::read($questionId);
    $questionName = $objQuestionTmp->selectTitle();
    $questionDescription = $objQuestionTmp->selectDescription();
    $questionWeighting = $objQuestionTmp->selectWeighting();
    $answerType = $objQuestionTmp->selectType();
    $quesId = $objQuestionTmp->selectId(); //added by priya saini
}

$objAnswerTmp = new Answer($questionId);
$nbrAnswers = $objAnswerTmp->selectNbrAnswers();
$choice = $exerciseResult[$questionId];
$destination = [];
$comment = '';
$next = 1;
Session::write('hotspot_coord', []);
Session::write('hotspot_dest', []);
$overlap_color = $missing_color = $excess_color = false;
$organs_at_risk_hit = 0;
$wrong_results = false;
$hot_spot_load = false;
$questionScore = 0;
$totalScore = 0;

switch ($answerType) {
    case MULTIPLE_ANSWER:
        $choiceValue = array_combine(array_values($choiceValue), array_values($choiceValue));
        break;
}
ob_start();
$result = $objExercise->manage_answer(0, $questionId, $choiceValue, 'exercise_show', null, false);
$contents = ob_get_clean();
/*
if (!empty($choiceValue)) {
    for ($answerId = 1; $answerId <= $nbrAnswers; $answerId++) {
        $answer = $objAnswerTmp->selectAnswer($answerId);
        $answerComment = $objAnswerTmp->selectComment($answerId);
        $answerDestination = $objAnswerTmp->selectDestination($answerId);

        $answerCorrect = $objAnswerTmp->isCorrect($answerId);
        $answerWeighting = $objAnswerTmp->selectWeighting($answerId);
        $numAnswer = $objAnswerTmp->selectAutoId($answerId);

        // Delineation
        $delineation_cord = $objAnswerTmp->selectHotspotCoordinates(1);
        $answer_delineation_destination = $objAnswerTmp->selectDestination(1);

        var_dump($choiceValue);
        switch ($answerType) {
            case CALCULATED_ANSWER:
                break;
            case DRAGGABLE:
                break;
            case MULTIPLE_ANSWER:
                $studentChoice = $choiceValue == $numAnswer ? 1 : 0;
                if ($studentChoice) {
                    $questionScore += $answerWeighting;
                    $totalScore += $answerWeighting;
                    $newQuestionList[] = $questionId;
                }
                break;
            case UNIQUE_ANSWER:
                $studentChoice = $choiceValue == $numAnswer ? 1 : 0;
                if ($studentChoice) {
                    $questionScore += $answerWeighting;
                    $totalScore += $answerWeighting;
                    $newQuestionList[] = $questionId;
                }
                break;
            case HOT_SPOT_DELINEATION:
                $studentChoice = $choice[$answerId];
                if ($studentChoice) {
                    $newQuestionList[] = $questionId;
                }
                if ($answerId === 1) {
                    $questionScore += $answerWeighting;
                    $totalScore += $answerWeighting;
                    $_SESSION['hotspot_coord'][1] = $delineation_cord;
                    $_SESSION['hotspot_dest'][1] = $answer_delineation_destination;
                }
                break;
        }

        if ($answerType == UNIQUE_ANSWER || $answerType == MULTIPLE_ANSWER) {
            if ($studentChoice) {
                $destination = $answerDestination;
                $comment = $answerComment;
            }
        } elseif ($answerType == HOT_SPOT_DELINEATION) {
            if ($next) {
                $hot_spot_load = true; //apparently the script is called twice
                $user_answer = $user_array;
                $_SESSION['exerciseResultCoordinates'][$questionId] = $user_answer; //needed for exercise_result.php

                // we compare only the delineation not the other points
                $answer_question = $_SESSION['hotspot_coord'][1];
                $answerDestination = $_SESSION['hotspot_dest'][1];

                $poly_user = convert_coordinates($user_answer, '/');
                $poly_answer = convert_coordinates($answer_question, '|');
                $max_coord = poly_get_max($poly_user, $poly_answer);

                if (empty($_GET['hotspot'])) { //no user response
                    $overlap = -2;
                } else {
                    $poly_user_compiled = poly_compile($poly_user, $max_coord);
                    $poly_answer_compiled = poly_compile(
                        $poly_answer,
                        $max_coord
                    );
                    $poly_results = poly_result(
                        $poly_answer_compiled,
                        $poly_user_compiled,
                        $max_coord
                    );

                    $overlap = $poly_results['both'];
                    $poly_answer_area = $poly_results['s1'];
                    $poly_user_area = $poly_results['s2'];
                    $missing = $poly_results['s1Only'];
                    $excess = $poly_results['s2Only'];
                }

                if ($overlap < 1) {
                    // shortcut to avoid complicated calculations
                    $final_overlap = 0;
                    $final_missing = 100;
                    $final_excess = 100;
                } else {
                    // the final overlap is the percentage of the initial polygon that is overlapped by the user's polygon
                    $final_overlap = round(((float) $overlap / (float) $poly_answer_area) * 100);
                    if ($dbg_local > 1) {
                        error_log(__LINE__.' - Final overlap is '.$final_overlap, 0);
                    }
                    // the final missing area is the percentage of the initial polygon that is not overlapped by the user's polygon
                    $final_missing = 100 - $final_overlap;
                    if ($dbg_local > 1) {
                        error_log(__LINE__.' - Final missing is '.$final_missing, 0);
                    }
                    // the final excess area is the percentage of the initial polygon's size that is covered by the user's polygon outside of the initial polygon
                    $final_excess = round((((float) $poly_user_area - (float) $overlap) / (float) $poly_answer_area) * 100);
                    if ($dbg_local > 1) {
                        error_log(__LINE__.' - Final excess is '.$final_excess, 0);
                    }
                }

                $destination_items = explode('@@', $answerDestination);
                $threadhold_total = $destination_items[0];
                $threadhold_items = explode(';', $threadhold_total);
                $threadhold1 = $threadhold_items[0]; // overlap
                $threadhold2 = $threadhold_items[1]; // excess
                $threadhold3 = $threadhold_items[2]; //missing

                // if is delineation
                if ($answerId === 1) {
                    //setting colors
                    if ($final_overlap >= $threadhold1) {
                        $overlap_color = true;
                    }

                    if ($final_excess <= $threadhold2) {
                        $excess_color = true;
                    }

                    if ($final_missing <= $threadhold3) {
                        $missing_color = true;
                    }

                    $try_hotspot = null;
                    $lp_hotspot = null;
                    $url_hotspot = null;
                    $select_question_hotspot = null;

                    // if pass
                    //if ($final_overlap>=$threadhold1 && $final_missing<=$threadhold2 && $final_excess<=$threadhold3) {
                    if ($final_overlap >= $threadhold1 && $final_missing <= $threadhold3 && $final_excess <= $threadhold2) {
                        $next = 1; //go to the oars
                        $result_comment = get_lang('Acceptable');
                    } else {
                        $next = 1; //Go to the oars. If $next =  0 we will show this message: "One (or more) area at risk has been hit" instead of the table resume with the results
                        $wrong_results = true;
                        $result_comment = get_lang('Unacceptable');
                    }

                    $special_comment = $comment = $answerDestination = $objAnswerTmp->selectComment(1);
                    $answerDestination = $objAnswerTmp->selectDestination(1);
                    $destination_items = explode('@@', $answerDestination);
                    $try_hotspot = $destination_items[1];
                    $lp_hotspot = $destination_items[2];
                    $select_question_hotspot = $destination_items[3];
                    $url_hotspot = $destination_items[4];
                } elseif ($answerId > 1) {
                    if ($objAnswerTmp->selectHotspotType($answerId) === 'noerror') {
                        // Type no error shouldn't be treated
                        $next = 1;
                        continue;
                    }
                    //check the intersection between the oar and the user
                    //echo 'user';	print_r($x_user_list);		print_r($y_user_list);
                    //echo 'official';print_r($x_list);print_r($y_list);
                    //$result = get_intersection_data($x_list,$y_list,$x_user_list,$y_user_list);

                    //$delineation_cord=$objAnswerTmp->selectHotspotCoordinates($answerId);
                    $delineation_cord = $objAnswerTmp->selectHotspotCoordinates($answerId); //getting the oars coordinates
                    $poly_answer = convert_coordinates($delineation_cord, '|');
                    // getting max coordinates
                    $max_coord = poly_get_max(
                        $poly_user,
                        $poly_answer
                    );
                    $test = false;
                    if (empty($_GET['hotspot'])) {
                        // no user response
                        $overlap = false;
                    } else {
                        // poly_compile really works tested with gnuplot
                        $poly_user_compiled = poly_compile(
                            $poly_user,
                            $max_coord,
                            $test
                        ); //$poly_user is already set when answerid = 1
                        $poly_answer_compiled = poly_compile(
                            $poly_answer,
                            $max_coord,
                            $test
                        );
                        $overlap = poly_touch(
                            $poly_user_compiled,
                            $poly_answer_compiled,
                            $max_coord
                        );
                    }

                    if ($overlap == false) $contents{
                        //all good, no overlap
                        $next = 1;
                        continue;
                    } else {
                        if ($dbg_local > 0) {
                            error_log(
                                __LINE__.' - Overlap is '.$overlap.': OAR hit',
                                0
                            );
                        }
                        $organs_at_risk_hit++;
                        //show the feedback
                        $next = 1;
                        $comment = $answerDestination = $objAnswerTmp->selectComment(
                            $answerId
                        );
                        $answerDestination = $objAnswerTmp->selectDestination(
                            $answerId
                        );
                        $destination_items = explode('@@', $answerDestination);
                        $try_hotspot = $destination_items[1];
                        $lp_hotspot = $destination_items[2];
                        $select_question_hotspot = $destination_items[3];
                        $url_hotspot = $destination_items[4];
                    }
                }
            }
        }
    }

    $overlap_color = 'red';
    if ($overlap_color) {
        $overlap_color = 'green';
    }

    $missing_color = 'red';
    if ($missing_color) {
        $missing_color = 'green';
    }
    $excess_color = 'red';
    if ($excess_color) {
        $excess_color = 'green';
    }

    if (!is_numeric($final_overlap)) {
        $final_overlap = 0;
    }

    if (!is_numeric($final_missing)) {
        $final_missing = 0;
    }
    if (!is_numeric($final_excess)) {
        $final_excess = 0;
    }

    if ($final_excess > 100) {
        $final_excess = 100;
    }

    $table_resume = '<table class="data_table">
    <tr class="row_odd">
        <td></td>
        <td ><b>'.get_lang('Requirements').'</b></td>
        <td><b>'.get_lang('YourAnswer').'</b></td>
    </tr>
    <tr class="row_even">
        <td><b>'.get_lang('Overlap').'</b></td>
        <td>'.get_lang('Min').' '.$threadhold1.'</td>
        <td><div style="color:'.$overlap_color.'">'.(($final_overlap < 0) ? 0 : intval($final_overlap)).'</div></td>
    </tr>
    <tr>
        <td><b>'.get_lang('Excess').'</b></td>
        <td>'.get_lang('Max').' '.$threadhold2.'</td>
        <td><div style="color:'.$excess_color.'">'.(($final_excess < 0) ? 0 : intval($final_excess)).'</div></td>
    </tr>

    <tr class="row_even">
        <td><b>'.get_lang('Missing').'</b></td>
        <td>'.get_lang('Max').' '.$threadhold3.'</td>
        <td><div style="color:'.$missing_color.'">'.(($final_missing < 0) ? 0 : intval($final_missing)).'</div></td>
    </tr>
    </table>';
}*/
Session::write('newquestionList', $newQuestionList);
$links = '';
if ($objExercise->getFeedbackType() === EXERCISE_FEEDBACK_TYPE_DIRECT) {
    if (isset($choiceValue) && $choiceValue == -1) {
        if ($answerType != HOT_SPOT_DELINEATION) {
            $links .= '<a href="#" onclick="tb_remove();">'.get_lang('ChooseAnAnswer').'</a><br />';
        }
    }
}

$destinationId = null;
if ($answerType != HOT_SPOT_DELINEATION) {
    if (!empty($destination)) {
        $item_list = explode('@@', $destination);
        $try = $item_list[0];
        $lp = $item_list[1];
        $destinationId = $item_list[2];
        $url = $item_list[3];
    }
    $table_resume = '';
} else {
    $try = $try_hotspot;
    $lp = $lp_hotspot;
    $destinationId = $select_question_hotspot;
    $url = $url_hotspot;
    $exerciseResult[$questionId] = 0;
    if ($organs_at_risk_hit == 0 && $wrong_results == false) {
        // no error = no oar and no wrong result for delineation
        // show if no error
        $comment = $answerComment = $objAnswerTmp->selectComment($nbrAnswers);
        $answerDestination = $objAnswerTmp->selectDestination($nbrAnswers);
        // we send the error
        $destination_items = explode('@@', $answerDestination);
        $try = $destination_items[1];
        $lp = $destination_items[2];
        $destinationId = $destination_items[3];
        $url = $destination_items[4];
        $exerciseResult[$questionId] = 1;
    }
}

// the link to retry the question
if (isset($try) && $try == 1) {
    $num_value_array = array_keys($questionList, $questionId);
    $links .= Display:: return_icon(
        'reload.gif',
        '',
        ['style' => 'padding-left:0px;padding-right:5px;']
    ).'<a onclick="SendEx('.$num_value_array[0].');" href="#">'.get_lang('TryAgain').'</a><br /><br />';
}

// the link to theory (a learning path)
if (!empty($lp)) {
    $lp_url = api_get_path(WEB_CODE_PATH).'lp/lp_controller.php?'.api_get_cidreq().'&action=view&lp_id='.$lp;
    $list = new LearnpathList(api_get_user_id());
    $flat_list = $list->get_flat_list();
    $links .= Display:: return_icon(
        'theory.gif',
        '',
        ['style' => 'padding-left:0px;padding-right:5px;']
    ).'<a target="_blank" href="'.$lp_url.'">'.get_lang('SeeTheory').'</a><br />';
}

$links .= '<br />';

// the link to an external website or link
if (!empty($url)) {
    $links .= Display:: return_icon(
        'link.gif',
        '',
        ['style' => 'padding-left:0px;padding-right:5px;']
    ).'<a target="_blank" href="'.$url.'">'.get_lang('VisitUrl').'</a><br /><br />';
}

if ($objExercise->getFeedbackType() === EXERCISE_FEEDBACK_TYPE_POPUP) {
    $nextQuestion = $questionNum + 1;
    $destinationId = isset($questionList[$nextQuestion]) ? $questionList[$nextQuestion] : -1;
}

// the link to finish the test
if ($destinationId == -1) {
    $links .= Display:: return_icon(
        'finish.gif',
        '',
        ['style' => 'width:22px; height:22px; padding-left:0px;padding-right:5px;']
    ).'<a onclick="SendEx(-1);" href="#">'.get_lang('EndActivity').'</a><br /><br />';
} else {
    // the link to other question
    if (in_array($destinationId, $questionList)) {
        $objQuestionTmp = Question::read($destinationId);
        $questionName = $objQuestionTmp->selectTitle();
        $num_value_array = array_keys($questionList, $destinationId);
        $icon = Display::return_icon(
                'quiz.png',
                '',
                ['style' => 'padding-left:0px;padding-right:5px;']
        );
        $links .= '<a onclick="SendEx('.$num_value_array[0].');" href="#">'.
                get_lang('Question').' '.$num_value_array[0].'</a>&nbsp;';
        $links .= $icon;
    }
}

echo '<script>
function SendEx(num) {
    if (num == -1) {
        window.location.href = "exercise_result.php?'.api_get_cidreq().'&take_session=1&exerciseId='.$exerciseId.'&num="+num+"&exerciseType='.$exerciseType.'&learnpath_item_id='.$learnpath_item_id.'&learnpath_id='.$learnpath_id.'";
    } else {
        num -= 1;
        window.location.href = "exercise_submit.php?'.api_get_cidreq().'&tryagain=1&exerciseId='.$exerciseId.'&num="+num+"&exerciseType='.$exerciseType.'&learnpath_item_id='.$learnpath_item_id.'&learnpath_id='.$learnpath_id.'";
    }    
    return false;
}
</script>';

if (!empty($links)) {
    if ($answerType == HOT_SPOT_DELINEATION) {
        if ($organs_at_risk_hit > 0) {
            $message .= '<br />'.get_lang('ResultIs').' <b>'.get_lang('Unacceptable').'</b><br />';
            $message .= '<p style="color:#DC0A0A;"><b>'.get_lang('OARHit').'</b></p>';
            $message .= '<p>'.$comment.'</p>';
        } else {
            $message = '<p>'.get_lang('YourDelineation').'</p>';
            $message .= $table_resume;
            $message .= '<br />'.get_lang('ResultIs').' <b>'.$result_comment.'</b><br />';
            $message .= '<p>'.$comment.'</p>';
        }
        echo '<br />';
        echo $message;
    } else {
        echo $objQuestionTmp->return_header(
            $objExercise,
            $questionNum,
            []
        );
        echo '<p>'.$contents.'</p>';
    }
    echo '<div style="padding-left: 450px"><h5>'.$links.'</h5></div>';
    echo '</div>';

    Session::write('hot_spot_result', $message);
    $_SESSION['hotspot_delineation_result'][$exerciseId][$questionId] = [$message, $exerciseResult[$questionId]];
    // Resetting the exerciseResult variable
    Session::write('exerciseResult', $exerciseResult);

    // Save this variables just in case the exercise loads an LP with other exercise
    Session::write('objExerciseExtra'.$exerciseId, Session::read('objExercise'));
    Session::write('exerciseResultExtra'.$exerciseId, Session::read('exerciseResult'));
    Session::write('questionListExtra'.$exerciseId, Session::read('questionList'));
} else {
    $questionNum++;
    echo '<script>
            window.location.href = "exercise_submit.php?exerciseId='.$exerciseId.'&num='.$questionNum.'&exerciseType='.$exerciseType.'&'.api_get_cidreq().'";
        </script>';
}
echo '</div>';
