<?php

if (IN_serendipity !== true) {
    die ("Don't hack!");
}

@serendipity_plugin_api::load_language(dirname(__FILE__));


class serendipity_event_lazyoutube extends serendipity_event {

    var $markup_elements = [];

    function introspect(&$propbag) {
        global $serendipity;

        $propbag->add('name',          PLUGIN_EVENT_LAZYOUTUBE_NAME);
        $propbag->add('description',   PLUGIN_EVENT_LAZYOUTUBE_DESC);
        $propbag->add('stackable',     false);
        $propbag->add('author',        'Malte Paskuda');
        $propbag->add('version',       '0.2');
        $propbag->add('requirements',  array(
            'serendipity' => '2.0',
        ));
        $propbag->add('cachable_events', array('frontend_display' => true));
        $propbag->add('event_hooks',   array('frontend_display' => true,
                                            'frontend_comment' => true,
                                            'external_plugin' => true,
                                            ));
        $propbag->add('groups', array('MARKUP'));

        $this->markup_elements = array(
            array(
              'name'     => 'ENTRY_BODY',
              'element'  => 'body',
            ),
            array(
              'name'     => 'EXTENDED_BODY',
              'element'  => 'extended',
            ),
            array(
              'name'     => 'COMMENT',
              'element'  => 'comment',
            ),
            array(
              'name'     => 'HTML_NUGGET',
              'element'  => 'html_nugget',
            )
        );

        $conf_array = array();
        foreach($this->markup_elements as $element) {
            $conf_array[] = $element['name'];
        }
        $propbag->add('configuration', $conf_array);
    }

    function install() {
        serendipity_plugin_api::hook_event('backend_cache_entries', $this->title);
    }

    function uninstall(&$propbag) {
        serendipity_plugin_api::hook_event('backend_cache_purge', $this->title);
        serendipity_plugin_api::hook_event('backend_cache_entries', $this->title);
    }

    function generate_content(&$title) {
        $title = PLUGIN_EVENT_LAZYOUTUBE_NAME;
    }


    function introspect_config_item($name, &$propbag)
    {
        $propbag->add('type',        'boolean');
        $propbag->add('name',        constant($name));
        $propbag->add('description', sprintf(APPLY_MARKUP_TO, constant($name)));
        $propbag->add('default', 'true');
        return true;
    }


    function event_hook($event, &$bag, &$eventData, $addData = null) {
        global $serendipity;

        $hooks = &$bag->get('event_hooks');

        if (isset($hooks[$event])) {
            switch($event) {
                case 'frontend_display':
                    foreach ($this->markup_elements as $temp) {
                        if (serendipity_db_bool($this->get_config($temp['name'], true)) && isset($eventData[$temp['element']]) &&
                            !($eventData['properties']['ep_disable_markup_' . $this->instance] ?? false) &&
                            !isset($serendipity['POST']['properties']['disable_markup_' . $this->instance])) {
                            $element = $temp['element'];
                            $eventData[$element] = $this->apply_markup($eventData[$element]);
                        }
                    }
                    return true;
                    break;
                case 'external_plugin':
                    $parts      = explode('_', $eventData);
                    if (count($parts) < 2) {
                        return false;
                    }
                    if ($parts[0] == 'lazyoutubefetch') {
                        $videoid = $parts[1];
                        $cache_path = $this->get_cache_path($videoid);
                        # Check if the thumbnail is already cached.
                        if (! file_exists($cache_path)) {
                            # If not we have to fetch and store it
                            # TODO: Allow this only for whitelisted video ids
                            $thumbnail_url = 'https://img.youtube.com/vi/' . $videoid .'/hqdefault.jpg';
                            $image_data = serendipity_request_url($thumbnail_url);
                            if (! file_exists(dirname($cache_path))) {
                                mkdir(dirname($cache_path));
                            }
                            $fp = fopen($cache_path, 'wb');
                            fwrite($fp, $image_data);
                            fclose($fp);
                        }
                        # Now it exists, we can output it. But we need to set the correct headers
                        $size = @getimagesize($cache_path);
                        $mime_type = $size['mime'];
                        header("Content-type: $mime_type");
                        header("Content-Length: ". filesize($cache_path));
                        echo file_get_contents($cache_path);
                        exit();
                    }
                    
                    return true;
                    break;

                default:
                    return false;
            }
        } else {
            return false;
        }
    }

    function get_cache_path($videoid) {
        global $serendipity;
        return $serendipity['serendipityPath'] . PATH_SMARTY_COMPILE . '/serendipity_event_lazytube/' . $videoid .'.jpg';
    }
    
    function get_cache_url($videoid) {
        global $serendipity;
        return $serendipity['baseURL'] . PATH_SMARTY_COMPILE . '/serendipity_event_lazytube/' . $videoid .'.jpg';
    }

    function getPermaPluginPath() {
        global $serendipity;

        // Get configured plugin path:
        $pluginPath = 'plugin';
        if (isset($serendipity['permalinkPluginPath'])){
            $pluginPath = $serendipity['permalinkPluginPath'];
        }

        return $pluginPath;
    }


    # Replaces the regular YouTube iframe with a simple thumbnail
    function apply_markup($text) {
        global $serendipity;
        //enable \ as escape-character:
        $text = str_replace('\[', chr(2), $text);
        $search = array(
                        // iframe embed                    optional privacy mode       #videoid     #start time etc
                        '/<iframe[^>]*https:\/\/www\.youtube(?:-nocookie)?\.com\/embed\/([^ \&"\?]+)([^"]*)[^>]*><\/iframe>/',
                       );   
        $search_elements = count($search);
        for($i = 0; $i < $search_elements; $i++) {
            $text = preg_replace_callback($search[$i], function ($matches) use ($serendipity) {
                            $videoid = $matches[1];
                            if (isset($matches[2]) && ! empty($matches[2])) {
                                $params = $matches[2] . '&autoplay=1';
                            } else {
                                $params = '?autoplay=1';
                            }
                            # TODO: Whitelist this videoid
                            $proxy_url = $serendipity['baseURL'] . $serendipity['indexFile'] . '?/' . $this->getPermaPluginPath() . '/lazyoutubefetch_' . $videoid;
                            return '<iframe
                                width="560"
                                height="315"
                                src="https://www.youtube-nocookie.com/embed/' . $videoid .'"
                                srcdoc="<style>*{padding:0;margin:0;overflow:hidden}html,body{height:100%}img,span{position:absolute;width:100%;top:0;bottom:0;margin:auto}span{height:1.5em;text-align:center;font:48px/1.5 sans-serif;color:white;text-shadow:0 0 0.5em black}</style><a href=https://www.youtube-nocookie.com/embed/' . $videoid . $params . '><img width=560 height=420 loading=lazy src=' . $proxy_url .'><span>▶</span></a>"
                                frameborder="0"
                                allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
                                allowfullscreen
                                ></iframe>';
                        },
                        $text);
        }
        //reinsert escaped charachters:
        $text = str_replace(chr(2), '[', $text);
        return $text;
    }

}

/* vim: set sts=4 ts=4 expandtab : */
?>
