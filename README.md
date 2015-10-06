# moodle-qformat_gapfill_quick

The gapfill_quick format is designed to import only Gapfill
questions. It is based on some of the ideas behind the Moodle
GIFT format. It should make it easy for people to 
quickly create bulk questions using a text editor.

It only allows the use of square braces [] as a gap delimiter and
uses {} for the delimiter for settings. The settings values recognised
are gapfill,noregex,fixedgapsize,noduplicates,casesensitive
Comment lines start with a double forward slash (//).
Optional question names are enclosed in double colon(::).
Overall feedback is indicated with hash mark #. Incorrect with #i#
partial with #p# and correct with #c#. See example import file for
full syntax.
