<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Gapfill quick question importer
 *
 * @package    qformat_gapfill_quick
 * @copyright  2015 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * The gapfill_quick format is designed to import only Gapfill
 * questions. It is based on some of the ideas behind the Moodle
 * GIFT format. It should make it easy for people to 
 * quickly create bulk questions using a text editor.
 * 
 * It only allows the use of square braces [] as a gap delimiter and
 * uses {} for the delimiter for settings. The settings values recognised
 * are gapfill,noregex,fixedgapsize,noduplicates,casesensitive
 * 
 *
 * Comment lines start with a double forward slash (//).
 * Optional question names are enclosed in double colon(::).
 * Overall feedback is indicated with hash mark #. Incorrect with #i#
 * partial with #p# and correct with #c#. See example import file for
 * full syntax. *
 *
 * @copyright  2015 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qformat_gapfill_quick extends qformat_default {

    public function provide_import() {
        return true;
    }

    /**
     * No point in exporting to a format that
     * is exclusive to this question type
     */
    public function provide_export() {
        return false;
    }

    protected function escapedchar_pre($string) {
        // Replaces escaped control characters with a placeholder BEFORE processing.

        $escapedcharacters = array("\\:", "\\#", "\\=", "\\{", "\\}", "\\~", "\\n");
        $placeholders = array("&&058;", "&&035;", "&&061;", "&&123;", "&&125;", "&&126;", "&&010");

        $string = str_replace("\\\\", "&&092;", $string);
        $string = str_replace($escapedcharacters, $placeholders, $string);
        $string = str_replace("&&092;", "\\", $string);
        return $string;
    }

    protected function escapedchar_post($string) {
        // Replaces placeholders with corresponding character AFTER processing is done.
        $placeholders = array("&&058;", "&&035;", "&&061;", "&&123;", "&&125;", "&&126;", "&&010");
        $characters = array(":", "#", "=", "{", "}", "~", "\n");
        $string = str_replace($placeholders, $characters, $string);
        return $string;
    }

    public function readquestion($lines) {
        // Given an array of lines known to define a question in this format, this function
        // converts it into a question object suitable for processing and insertion into Moodle.

        $question = $this->get_default_gapfill();


        // REMOVED COMMENTED LINES and IMPLODE.
        foreach ($lines as $key => $line) {
            $line = trim($line);
            if (substr($line, 0, 2) == '//') {
                $lines[$key] = ' ';
            }
        }

        $text = trim(implode("\n", $lines));
        if ($text == '') {
            return false;
        }

        // Substitute escaped control characters with placeholders.
        $text = $this->escapedchar_pre($text);

        // Look for category modifier.
        if (preg_match('~^\$CATEGORY:~', $text)) {
            $newcategory = trim(substr($text, 10));
            // Build fake question to contain category.
            $question->qtype = 'category';
            $question->category = $newcategory;
            return $question;
        }
        $description = false;

        $question->name = $this->question_name_parser($text);
        $question->questiontext = $this->question_text_parser($text, $question);
        $question = $this->get_distractors($text, $question);
        $question->generalfeedback = $this->get_feedback($text, $question, "#");
        $question->incorrectfeedback['text'] = $this->get_feedback($text, $question, "#i#");
        $question->partiallycorrectfeedback['text'] = $this->get_feedback($text, $question, "#p#");
        $question->correctfeedback['text'] = $this->get_feedback($text, $question, "#c#");
        $question = $this->get_settings($text, $question);

        // Set question name if not already set.
        if ($question->name === false) {
            $question->name = $this->create_default_question_name($question->questiontext, get_string('questionname', 'question'));
        }
        return $question;
    }

    protected function get_settings($text, $question) {

        $start = strpos($text, '{');
        if ($start == 0) {
            return $question;
        }
        $this->check_delim("{", "}", $text);
        /* +2 to chop off the { */
        $found = substr($text, $start + 1, strlen($text));
        $end = strpos($found, '}');
        $found = substr($found, 0, $end);
        $settings = explode(',', $found);
        $this->check_settings($settings, $found);
        foreach ($settings as $setting) {
            if ($setting == 'gapfill') {
                $question->answerdisplay = 'gapfill';
            }
            if ($setting == 'dropdown') {
                $question->answerdisplay = 'dropdown';
            }
            if ($setting == 'noregex') {
                $question->disableregex = true;
            }
            if ($setting == 'fixedgapsize') {
                $question->fixedgapsize = true;
            }
            if ($setting == 'noduplicates') {
                $question->noduplicates = true;
            }
            if ($setting == 'casesensitive') {
                $question->casesensitive = true;
            }
        }
        return $question;
    }

    /*     *
     * Incorrect answers designed to distract. Only makes sens in dropdown or
     * dragdrop mode
     */

    protected function get_distractors($text, $question) {
        $this->check_delim("~[", "]", $text);
        $distractorstart = strpos($text, '~[');
        if ($distractorstart == 0) {
            return $question;
        }
        /* +2 to chop off the ~[ */
        $distractortext = substr($text, $distractorstart + 2, strlen($text));
        $distractorend = strpos($distractortext, ']');
        $distractortext = substr($distractortext, 0, $distractorend);
        $question->wronganswers['text'] = $distractortext;
        return $question;
    }

    protected function get_feedback($text, $question, $delimiter) {
        $feedbackstart = strpos($text, $delimiter);
        if ($feedbackstart == 0) {
            return "";
        }
        $feedback = substr($text, $feedbackstart + strlen($delimiter), strlen($text));
        $feedbackend = strpos($feedback, $delimiter);
        if ($feedbackend == 0) {
            $this->error(get_string('delimitmatcherror', 'qformat_gapfill_quick', $delimiter), $text);
        }
        $feedback = substr($feedback, 0, $feedbackend);
        return $feedback;
    }

    protected function question_name_parser($text) {
        // Question name parser.
        $questionname = "";
        if (substr($text, 0, 2) == '::') {
            $text = substr($text, 2);

            $namefinish = strpos($text, '::');
            if ($namefinish === false) {
                $questionname = false;
                // Name will be assigned after processing question text below.
            } else {
                $questionname = substr($text, 0, $namefinish);
                $questionname = $this->clean_question_name($this->escapedchar_post($questionname));
                $text = trim(substr($text, $namefinish + 2)); // Remove name from text.
            }
        } else {
            $questionname = false;
        }
        return $questionname;
    }

    protected function get_default_gapfill() {

        $question = $this->defaultquestion();
        $question->qtype = 'gapfill';
        $question->delimitchars = "[]";
        $question->answerdisplay = 'dragdrop';
        $question->casesensitive = false;
        $question->noduplicates = false;
        $question->disableregex = false;
        $question->fixedgapsize = false;
        $question->feedbackformat = FORMAT_MOODLE;

        $question->correctfeedback['text'] = '';
        $question->correctfeedback['format'] = FORMAT_MOODLE;

        $question->partiallycorrectfeedback['text'] = '';
        $question->partiallycorrectfeedback['format'] = FORMAT_MOODLE;

        $question->incorrectfeedback['text'] = '';
        $question->incorrectfeedback['format'] = FORMAT_MOODLE;
        return $question;
    }

    protected function question_text_parser($text, $question) {
        $answerstart = strlen($question->name);
        if ($question->name != false) {
            /* allow for start and end :: */
            $answerstart+=4;
        }
        $questiontext = substr($text, $answerstart, strlen($text));
        $this->check_delim("[", "]", $questiontext);

        $wrongpos = strpos($questiontext, "~[");
        if ($wrongpos > 0) {
            $questiontext = trim(substr($text, $answerstart, $wrongpos - 1));
            return $questiontext;
        }
        $settingpos = strpos($questiontext, "{");
        if ($settingpos > 0) {
            $questiontext = trim(substr($text, $answerstart, $settingpos - 1));
            return $questiontext;
        }

        return $questiontext;



        $length = strpos($questiontext, '#');
        if ($length > 0) {
            $questiontext = trim(substr($text, $answerstart, $length - 1));
            return $questiontext;
        }
        $length = strpos($questiontext, '##');
        if ($length > 0) {
            $questiontext = trim(substr($text, $answerstart, $length - 1));
            return $questiontext;
        }
        $this->match_delimiters('{', '}', $questiontext, $text);

        return $questiontext;
    }

    /**
     * Checks that a start delimiter has a matching close delimmiter
     * @param string $startdelim
     * @param string $enddelim
     * @param string $text
     */
    protected function check_delim($startdelim, $enddelim, $text) {
        $textarray = str_split($text);
        $unbalanced = 0;
        foreach ($textarray as $key => $character) {
            if ($character === $startdelim) {
                $unbalanced++;
            }
            if ($character === $enddelim) {
                $unbalanced--;
            }
        }
        if ($unbalanced > 0) {
            $this->error(get_string('closingdelimiterror', 'qformat_gapfill_quick', $enddelim), $text);
        }
    }

 
    /**
     * Checks that a start delimiter has a matching close delimmiter
     * @param string $startdelim
     * @param string $enddelim
     * @param string $text
     */
    protected function check_settings($settings, $settingstring) {
        $validsettings = Array("casesensitive", "disableregex", "noduplicates", "fixedgapsize", "dropdowns", "gapfill", "dragdrop");
        foreach ($settings as $s) {
            if (!in_array($s, $validsettings)) {
                $this->error(get_string('settingerror', 'qformat_gapfill_quick', $s), '{'.$settingstring.'}');
            }
        }
    }

}
