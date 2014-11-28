<?php
class PluginHandler extends Handler_Protected
{
    function csrf_ignore($method)
    {
        return true;
    }

    function catchall($method)
    {
        $plugin = PluginHost::getInstance()->get_plugin($_REQUEST["plugin"]);

        if (!$plugin) {
            print json_encode(array("error" => "PLUGIN_NOT_FOUND"));
            return;
        }

        if (!method_exists($plugin, $method)) {
            print json_encode(array("error" => "METHOD_NOT_FOUND"));
            return;
        }

        $plugin->$method();
    }
}
