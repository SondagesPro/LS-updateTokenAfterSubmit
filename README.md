# updateTokenAfterSubmit for LimeSurvey

Allow to update token code after submission

This plugin was tested with version 2.15.5

## Installation

### Via GIT
- Go to your LimeSurvey Directory (version up to 3.0)
- Clone in plugins/updateTokenAfterSubmit directory

### Via ZIP dowload
- Get the file [updateTokenAfterSubmit.zip](https://extensions.sondages.pro/IMG/auto/updateTokenAfterSubmit.zip)
- Extract : `unzip updateTokenAfterSubmit.zip`
- Move the directory to plugins/ directory inside LimeSUrvey


## Usage

At plugin settings page for each survey you can set

- _fixedCode_ : a fixed code added to token value, remind max len of token are 35 characters.
- _keepTrackOn_ : an attribute to keep track of number of update done. The value of the attribute is set to updateTokenAfterSubmit-{number of update}. Email status is a good candidate for this.
- _resetToken_ : reset token (and response) to not submitted. And add a Use left.

## Issues and feature

All issue and merge request must be done in [base repo](https://gitlab.com/SondagesPro/managament/updateTokenAfterSubmit) (currently gitlab).

Issue must be posted with complete information : LimeSurvey version and build number, web server version, PHP version, SQL type and version number …

**Reminder:** no warranty of functionnal system operation is offered on this software. If you want a professional offer: please [contact Sondages Pro](https://extensions.sondages.pro/about/contact.html).

## Home page & Copyright
- HomePage <http://extensions.sondages.pro/>
- Copyright © 2018 Denis Chenu <http://sondages.pro>
- Copyright © 2018 Réjean Prévost
- Licence : GNU Affero General Public License <https://www.gnu.org/licenses/agpl-3.0.html>

