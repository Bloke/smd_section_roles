<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_section_roles';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.1.0';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'https://stefdawson.com/';
$plugin['description'] = 'Limit publishing Textpattern articles to specific sections by role';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '4';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '3';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

$plugin['textpack'] = <<<EOT
#@owner smd_section_roles
#@language en, en-gb, en-us
#@prefs
smd_section_roles => Section roles
smd_section_roles_restricted => Restricted section
EOT;

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * smd_section_roles plugin for Textpattern CMS.
 *
 * @author Stef Dawson
 * @license GNU GPLv2
 * @link http://github.com/Bloke/smd_section_roles
 */

if (txpinterface === 'admin') {
    $smd_section_roles = new smd_section_roles();
    $smd_section_roles->install();
}

class smd_section_roles
{
    protected $event = 'smd_section_roles';
    protected $version = '0.1.0';
    protected $privs = '1';
    protected $all_privs = '1,2,3,4,5,6';

    /**
     * Constructor.
     */
    public function __construct()
    {
        add_privs('plugin_prefs.'.$this->event, $this->privs);
        add_privs('prefs.'.$this->event, $this->privs);

        register_callback(array($this, 'install'), 'plugin_lifecycle.'.$this->event);
        register_callback(array($this, 'options'), 'plugin_prefs.'.$this->event, null, 1);
        register_callback(array($this, 'stopId'), 'article', 'edit', 1);
        register_callback(array($this, 'constrain'), 'article_ui', 'validate_save');
        register_callback(array($this, 'constrain'), 'article_ui', 'validate_publish');
        register_callback(array($this, 'setSections'), 'article_ui', 'section');
        register_callback(array($this, 'multiEditOpts'), 'list_ui', 'multi_edit_options');
        register_callback(array($this, 'skipSections'), 'admin_criteria', 'list_list');
        register_callback(array($this, 'skipSections'), 'txp.article', 'neighbour.criteria');

        // Ensure installation/upgrade has taken place in case lifecycle isn't fired.
        $this->install();
    }

    /**
     * Installer.
     *
     * @param string $evt Admin-side event.
     * @param string $stp Plugin-lifecycle step.
     */
    public function install($evt = '', $stp = '')
    {
        global $prefs;

        // Remove prefs if plugin deleted.
        if ($stp == 'deleted') {
            safe_delete(
                'txp_prefs',
                "name like 'smd\_section\_roles\_%'"
            );

            return;
        }

        // Version number unused at present but could be used
        // for detecting upgrades in future.
        $current = isset($prefs['smd_section_roles_version'])
            ? $prefs['smd_section_roles_version']
            : 'base';

        // Install the prefs.
        $plugprefs = $this->get_prefs();

        foreach ($plugprefs as $name => $opts) {
            if (get_pref($name, null) === null) {
                set_pref(
                    $name,
                    (isset($opts['default']) ? $opts['default'] : ''),
                    (isset($opts['event']) ? $opts['event'] : $this->event),
                    (isset($opts['type']) ? $opts['type'] : PREF_PLUGIN),
                    (isset($opts['html']) ? $opts['html'] : 'text_input'),
                    $opts['position'],
                    (isset($opts['scope']) ? $opts['scope'] : PREF_GLOBAL)
                );
            }
        }

        // Update the installed version number.
        set_pref('smd_section_roles_version', $this->version, $this->event, 2, '', 0);
        $prefs['smd_section_roles_version'] = $this->version;
    }

    /**
     * Set the Section dropdown to a reduced set of options.
     *
     * @param  string $evt   Textpattern event
     * @param  string $stp   Textpattern step (action)
     * @param  string $block Current HTML block
     * @param  array  $rs    Record set of article being edited
     * @return string       HTML
     */
    public function setSections($evt, $stp, $block, $rs)
    {
        $options = $this->makeOpts();
        $js = $prepend = '';

        if (count($options) === 1) {
            $name = array('name' => 'Section', 'class' => 'ui-helper-hidden');
            $prepend = current($options)['title'];
            $js = script_js(<<<EOJS
$(document).ready(function () {
    $('#section').change();
});
EOJS
            );
        } else {
            $name = 'Section';
        }

        $out = $prepend.selectInput($name, $options, $rs['Section'], false, '', 'section').$js;

        return inputLabel(
            'section',
            $out.
            (has_privs('section.edit') ? n.eLink('section', 'list', '', '', gTxt('edit'), '', '', '', 'txp-option-link') : ''),
            'section',
            array('', 'instructions_section'),
            array('class' => 'txp-form-field section')
        );
    }

    /**
     * Set the Section dropdown to a reduced set of options.
     *
     * @param  string $evt   Textpattern event
     * @param  string $stp   Textpattern step (action)
     * @param  array  $multi Set of multi-edit options to be rendered
     */
    public function multiEditOpts($evt, $stp, &$multi)
    {
        $options = $this->makeOpts();

        if (count($options) === 1) {
            unset($multi['changesection']);
        } else {
            $multi['changesection']['html'] = selectInput('Section', $options, '', true);
        }
    }

    /**
     * Limit the articles to certain sections when listing/navigating articles.
     *
     * @param  string $evt Textpattern event
     * @param  string $stp Textpattern step (action)
     * @return string     Query criteria modifications
     */
    public function skipSections($evt, $stp)
    {
        $sections = $this->userSections();

        return " AND Section IN (".implode(',', quote_list($sections)).")";
    }

    /**
     * Limit the publishable sections to those defined for the user's role.
     *
     * @param string $evt         Textpattern event
     * @param string $stp         Textpattern step (action)
     * @param array  $rs          Record set of article being edited
     * @param array  $constraints Set of article constraints
     */
    public function constrain($evt, $stp, $rs, $constraints)
    {
        $options = $this->userSections($rs['AuthorID']);

        $constraints['Section']->setOptions($options, 'choices');
        $constraints['Section']->setOptions(gTxt('smd_section_roles_restricted'), 'message');
    }

    /**
     * Prevent article IDs outside the allowed sections from being edited/viewed.
     *
     * @param string $evt         Textpattern event
     * @param string $stp         Textpattern step (action)
     * @param array  $rs          Record set of article being edited
     * @param array  $constraints Set of article constraints
     */
    public function stopId($evt, $stp)
    {
        $options = $this->userSections();
        $ID = intval(gps('ID'));

        if ($ID) {
            $artSection = safe_field('Section', 'textpattern', "ID={$ID}");

            if (!in_array($artSection, $options)) {
                pagetop(gTxt('restricted_area'));
                echo graf(gTxt('restricted_area'), array('class' => 'restricted-area'));
                end_page();
                exit;
            }
        }
    }

    /**
     * Construct name=>value array pairs of restricted section.
     *
     * @return array
     */
    protected function makeOpts()
    {
        global $txp_sections;

        $sections = $this->userSections();
        $options = array();

        foreach ($sections as $sec) {
            $options[$sec] = array('title' => $txp_sections[$sec]['title'], 'data-skin' => $txp_sections[$sec]['skin']);
        }

        return $options;
    }

    /**
     * Fetch the set of sections that an author can post in.
     *
     * @param  string $AuthorID User name to look up. Uses logged-in user if not supplied
     * @return array            List of sections in which that users' role is permitted to post
     */
    protected function userSections($authorID = null)
    {
        global $txp_user, $txp_sections;

        static $level = null, $options = null;

        if ($level === null) {
            $user = doSlash(empty($authorID) ? $txp_user : $authorID);
            $level = safe_field("privs", 'txp_users', "name = '".$user."'");
        }

        if ($options === null) {
            $options = get_pref('smd_section_roles_'.$level);

            if ($options === 'SMD_ALL') {
                $options = array_keys($txp_sections);
            } else {
                $options = do_list($options);
            }
        }

        return $options;
    }

    /**
     * Jump to the prefs panel from the Options link on the Plugins panel.
     */
    public function options()
    {
        $link = '?event=prefs#prefs_group_'.$this->event;

        header('Location: ' . $link);
    }

    /**
     * Display a section select list, defaulting to all selected.
     */
    public function sectionList($key, $val)
    {
        global $txp_sections;

        $options = array();
        $selected = array();

        if ($val !== 'SMD_ALL') {
            $selected = do_list($val);
        }

        foreach ($txp_sections as $section => $data) {
            if ($section === 'default') {
                continue;
            }

            $options[$section] = $data['title'];

            if ($val === 'SMD_ALL') {
                $selected[] = $section;
            }
        }

        return selectInput($key, $options, $selected);
    }

    /**
     * Construct the preference option definitions.
     *
     * @return  array
     */
    function get_prefs()
    {
        global $txp_groups;

        $lang = get_pref('language_ui');

        $plugprefs = array();

        foreach ($txp_groups as $priv => $grp) {
            if ($grp === 'privs_none') {
                continue;
            }

            $plugprefs['smd_section_roles_'.$priv] = array(
                'html'     => $this->event . '->sectionList',
                'position' => $priv,
                'default'  => 'SMD_ALL',
            );

            safe_upsert('txp_lang',
                array(
                    'data'  => gTxt($grp),
                    'event' => 'prefs',
                    'owner' => $this->event,
                ), array(
                    'name' => 'smd_section_roles_'.$priv,
                    'lang' => $lang,
                )
            );
        }

        return $plugprefs;
    }
}

# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. smd_section_roles

h2. Usage

# Install the plugin.
# Visit Admin>Prefs:Section roles.
# Choose sections that each user level can post in.
# Save the prefs.

Depending on the restrictions put in place, users can then:

* Only save/publish articles to one of the nominated sections.
* Only see articles on the Articles list panel in sections they're permitted to post.
* Only assign articles via multi-edit to sections they have access.
* Only navigate next/prev between articles in sections to which they've been given access.
* Not see/edit articles outside of their nominated sections.

h2. Known issues

* Doesn't (yet) restrict the multi-edit section assignment after submission.

# --- END PLUGIN HELP ---
-->
<?php
}
?>