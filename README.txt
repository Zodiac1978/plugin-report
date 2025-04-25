=== Plugin Report ===
Contributors: roytanck, zodiac1978, pedromendonca
Tags: plugin info, admin, plugins, multisite
Requires at least: 4.6
Tested up to: 6.8
Requires PHP: 5.6
Stable tag: 2.2.1
License: GPLv3

A WordPress plugin that provides detailed information about currently installed plugins.

##Plugin Report will allow you to:

* Spot plugins that are no longer maintained.
* Get a quick overview of the "plugin health" of your site.
* Provide clients with a detailed report, right from their own dashboard, or as CSV spreadsheet.
* Find plugins that are no longer active on multisite installs


== Frequently Asked Questions ==

= Where can I submit bug reports or feature requests? =

The development of this plugin happens on [GitHub](https://github.com/Zodiac1978/plugin-report). Feel free to [submit issues](https://github.com/Zodiac1978/plugin-report/issues) there.


== Screenshots ==
 
1. Your plugin report is found under the Plugins menu, or in the Network section if your site is a multisite install.


== Credits ==

Thanks to Roy Tanck for trusting me to adopt this great plugin. Hartelijk bedankt!

Special thanks go to [Tristen Forsythe Brown](http://tristen.ca/) for the [tablesort JavaScript library](https://github.com/tristen/tablesort) licensed under the MIT License.


== Changelog ==

= 2.2.1 (2025-04-25) =
* Fix plugin for subfolder installs

= 2.2.0 (2025-04-25) =
* Tablesort updated to the latest version (5.6.0)
* Many code improvements (Thanks to PHPStan, WPCS and Plugin Check)
* New ownership
* Tested with WP 6.8

= 2.1.1 (2022-06-17) =
* Improved behavior of the repository column on older WordPress versions (thanks, @zodiac1978)

= 2.1 (2022-05-28) =
* Added detection for plugins that have been closed in the wordpress.org repository

= 2.0.2 (2022-02-12) =
* Fixed some more PHP warnings

= 2.0.1 (2022-02-03) =
* Fixed PHP warnings caused by an undefined variable

= 2.0.0 (2021-12-17) =
* Added a new column to display repository information and detect possible supply chain issues
* Tablesort updated to the latest version

= 1.9.3 (2021-11-26) =
* Fixed an issue where the exported CSV filename contained the wrong month (thanks, @zodiac1978)

= 1.9.2 (2021-10-03) =
* Skip the wordpress.org API call if the plugin's Update URI is set (thanks, @zodiac1978)
* Tested with WP 5.8

= 1.9.1 (2021-05-02) =
* Fixed a minor issues that could cause problems with the plugin's translations

= 1.9 (2021-05-02) =
* Display translated plugin info when available (thanks, @zodiac1978)
* Fixed default sorting of plugins to match WP's plugins screen (thanks, @zodiac1978)

= 1.8.3 (2021-03-19) =
* Fixed an issue where plugin auto-updates were not displayed correctly

= 1.8.2 (2020-12-16) =
* Coding standards and i18n improvements (thanks @pedromendonca)
* Tested with WordPress 5.6
* Updated tablesort to version 5.2.1

= 1.8.1 (2020-10-28) =
* Modified the way WP version numbers are compared to fix issues with non-repo plugins and non-standard version numbering

= 1.8 (2020-08-17) =
* Fixed a jQuery issue causing errors in WP 5.5
* Added column to display whether a plugin is set to auto-update

= 1.7 (2020-04-03) =
* Adds column sorting (props @zodiac1978)
* Replaces the Excel export with a more robust CSV export function
* Uses HTML's progress element for the progress bar

= 1.6.1 (2020-03-21) =
* Fixed an issue with version comparisons and beta/RC versions (thanks @zodiac1978)
* Coding standards improvements (thanks @zodiac1978)
* The plugin can now only be network-activated on multisite
* Tested against WordPress 5.4

= 1.6 (2020-03-14) =
* Further i18n improvements (thanks @pedromendonca)
* Minor version differences no longer shown as "medium" risk
* When a plugin upgrade requires platform upgrades, this is now shown (suggested by @zodiac1978)
* Improved the way CSS and javascript files are enqueued

= 1.5 (2020-02-09) =
* The activation column now shows the number of activations on multisite
* I18n improvements (thanks @pedromendonca!)

= 1.4 (2020-01-17) =
* Adds an .xls export function (experimental)
* Adds an "activated" column to the report table

= 1.3 (2019-11-29) =
* Better table styles, including cell background colors
* Improved error messages
* Cached information is now automatically refreshed when a plugin is updated through wp-admin

= 1.2 (2019-11-26) =
* Removed the "compatibility" column (data no longer provided by API)
* Fixed an issue with long plugin slugs causing invalide transient keys
* Fixed an issue where version number colors were inconsistent

= 1.1 (2019-11-23) =
* Code cleanup
* Adds proper multisite support
* Accessibility and internationalisation improvements
 
= 1.0 (2017-01-17) =
* Initial version
