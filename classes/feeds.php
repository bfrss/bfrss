<?php
require_once "colors.php";

class Feeds extends Handler_Protected
{
    function csrf_ignore($method)
    {
        $csrf_ignored = array("index", "feedbrowser", "quickaddfeed", "search");

        return array_search($method, $csrf_ignored) !== false;
    }

    private function format_headline_subtoolbar(
        $feed_site_url,
        $feed_title,
        $feed_id,
        $is_cat,
        $search,
        $search_mode,
        $view_mode,
        $error,
        $feed_last_updated
    ) {
        // Initialize template engine
        $loader = new Twig_Loader_Filesystem('templates/html/classes/feeds');
        $twig = new Twig_Environment($loader, array('cache' => 'cache/templates'));

        // Load template
        $template = $twig->loadTemplate('format_headline_subtoolbar.html');
        $template_vars = array(
            'catchup_sel_link' => 'catchupSelection()',
            'archive_sel_link' => 'archiveSelection()',
            'delete_sel_link' => 'deleteSelection()',
            'sel_all_link' => 'selectArticles("all")',
            'sel_unread_link' => 'selectArticles("unread")',
            'sel_inv_link' => 'selectArticles("invert")',
            'sel_none_link' => 'selectArticles("none")',
            'tog_unread_link' => 'selectionToggleUnread()',
            'tog_marked_link' => 'selectionToggleMarked()',
            'tog_published_link' => 'selectionTogglePublished()',
            'set_score_link' => 'setSelectionScore()',
        );

        // Fill template variables
        $template_vars['feed_id'] = $feed_id;
        $template_vars['is_cat'] = $is_cat;

        if ($is_cat) {
            $cat_q = "&is_cat=".$is_cat;
        }

        if ($search) {
            $search_q = "&q=".$search."&smode=".$search_mode;
        } else {
            $search_q = "";
        }

        $template_vars['rss_link'] = htmlspecialchars(
            get_self_url_prefix() . "/public.php?op=rss&id=".$feed_id . $cat_q . $search_q
        );

        if ($error) {
            $template_vars['show_error'] = true;
            $template_vars['error'] = $error;
        }

        if ($feed_site_url) {
            $template_vars['show_feed_site_url'] = true;
            $template_vars['feed_site_url'] = $feed_site_url;
            $template_vars['last_updated'] = T_sprintf("Last updated: %s", $feed_last_updated);
            $template_vars['feed_title'] = truncate_string($feed_title, 30);
        } else {
            $template_vars['feed_title'] = $feed_title;
        }

        $template_vars['isarchive'] = $feed_id == "0";

        if (PluginHost::getInstance()->get_plugin("mail")) {
            $template_vars['mail'] = true;
        }

        if (PluginHost::getInstance()->get_plugin("mailto")) {
            $template_vars['mailto'] = true;
        }

        $template_vars['hook_toolbar_buttons'] = array();
        foreach (PluginHost::getInstance()->get_hooks(PluginHost::HOOK_HEADLINE_TOOLBAR_BUTTON) as $p) {
            array_push(
                $template_vars['hook_toolbar_buttons'],
                $p->hook_headline_toolbar_button($feed_id, $is_cat)
            );
        }

        // Render the template
        return $template->render($template_vars);
    }

    private function format_headlines_list(
        $feed, // part of return value
        $method,
        $view_mode,
        $limit,
        $cat_view,
        $next_unread_feed,
        $offset,
        $vgr_last_feed = false,
        $override_order = false,
        $include_children = false
    ) {
        if (isset($_REQUEST["DevForceUpdate"])) {
            header("Content-Type: text/plain; charset=utf-8");
        }

        $disable_cache = false; // part of return value

        $reply = array(); // part of return value

        $rgba_cache = array();

        $timing_info = microtime(true);

        $topmost_article_ids = array(); // part of return value

        if (!$offset) {
            $offset = 0;
        }
        if ($method == "undefined") {
            $method = "";
        }

        $method_split = explode(":", $method);

        if ($method == "ForceUpdate" && $feed > 0 && is_numeric($feed)) {
            // Update the feed if required with some basic flood control

            $result = $this->dbh->query(
                "SELECT cache_images,".SUBSTRING_FOR_DATE."(last_updated,1,19) AS last_updated
                FROM ttrss_feeds WHERE id = '".$feed."'"
            );

            if ($this->dbh->num_rows($result) != 0) {
                $last_updated = strtotime($this->dbh->fetch_result($result, 0, "last_updated"));
                $cache_images = sql_bool_to_bool($this->dbh->fetch_result($result, 0, "cache_images"));

                if (!$cache_images && time() - $last_updated > 120 || isset($_REQUEST['DevForceUpdate'])) {
                    include "rssfuncs.php";
                    update_rss_feed($feed, true, true);
                } else {
                    $this->dbh->query(
                        "UPDATE ttrss_feeds
                        SET last_updated = '1970-01-01', last_update_started = '1970-01-01'
                        WHERE id = '".$feed."'"
                    );
                }
            }
        }

        if ($method_split[0] == "MarkAllReadGR") {
            catchup_feed($method_split[1], false);
        }

        // FIXME: might break tag display?

        if (is_numeric($feed) && $feed > 0 && !$cat_view) {
            $result = $this->dbh->query(
                "SELECT id FROM ttrss_feeds WHERE id = '$feed' LIMIT 1"
            );

            if ($this->dbh->num_rows($result) == 0) {
                $reply['content'] = "<div align='center'>".__('Feed not found.')."</div>";
            }
        }

        @$search = $this->dbh->escape_string($_REQUEST["query"]);

        if ($search) {
            $disable_cache = true;
        }

        @$search_mode = $this->dbh->escape_string($_REQUEST["search_mode"]);

        if ($_REQUEST["debug"]) {
            $timing_info = print_checkpoint("H0", $timing_info);
        }

        if ($search_mode == '' && $method != '') {
            $search_mode = $method;
        }

        if (!$cat_view && is_numeric($feed) && $feed < PLUGIN_FEED_BASE_INDEX && $feed > LABEL_BASE_INDEX) {
            $handler = PluginHost::getInstance()->get_feed_handler(
                PluginHost::feed_to_pfeed_id($feed)
            );

            if ($handler) {
                $options = array(
                    "limit" => $limit,
                    "view_mode" => $view_mode,
                    "cat_view" => $cat_view,
                    "search" => $search,
                    "search_mode" => $search_mode,
                    "override_order" => $override_order,
                    "offset" => $offset,
                    "owner_uid" => $_SESSION["uid"],
                    "filter" => false,
                    "since_id" => 0,
                    "include_children" => $include_children);

                $qfh_ret = $handler->get_headlines(
                    PluginHost::feed_to_pfeed_id($feed),
                    $options
                );
            }

        } else {
            $qfh_ret = queryFeedHeadlines(
                $feed,
                $limit,
                $view_mode,
                $cat_view,
                $search,
                $search_mode,
                $override_order,
                $offset,
                0,
                false,
                0,
                $include_children
            );
        }

        $vfeed_group_enabled = get_pref("VFEED_GROUP_BY_FEED") && $feed != -6;

        if ($_REQUEST["debug"]) {
            $timing_info = print_checkpoint("H1", $timing_info);
        }

        $result = $qfh_ret[0];
        $feed_title = $qfh_ret[1];
        $feed_site_url = $qfh_ret[2];
        $last_error = $qfh_ret[3];
        $last_updated = strpos($qfh_ret[4], '1970-') === false ?
            make_local_datetime($qfh_ret[4], false) :
            __("Never");
        $highlight_words = $qfh_ret[5];

        $vgroup_last_feed = $vgr_last_feed; // part of return value

        $reply['toolbar'] = $this->format_headline_subtoolbar(
            $feed_site_url,
            $feed_title,
            $feed,
            $cat_view,
            $search,
            $search_mode,
            $view_mode,
            $last_error,
            $last_updated
        );

        $headlines_count = $this->dbh->num_rows($result); // part of return value

        if ($offset == 0) {
            foreach (PluginHost::getInstance()->get_hooks(PluginHost::HOOK_HEADLINES_BEFORE) as $p) {
                 $reply['content'] .= $p->hook_headlines_before($feed, $cat_view, $qfh_ret);
            }
        }

        if ($this->dbh->num_rows($result) > 0) {

            $lnum = $offset;

            $num_unread = 0;
            $cur_feed_title = '';

            if ($_REQUEST["debug"]) {
                $timing_info = print_checkpoint("PS", $timing_info);
            }

            $expand_cdm = get_pref('CDM_EXPANDED');

            while ($line = $this->dbh->fetch_assoc($result)) {
                $line["content_preview"] =  "&mdash; " . truncate_string(strip_tags($line["content"]), 250);

                foreach (PluginHost::getInstance()->get_hooks(PluginHost::HOOK_QUERY_HEADLINES) as $p) {
                    $line = $p->hook_query_headlines($line, 250, false);
                }

                if (get_pref('SHOW_CONTENT_PREVIEW')) {
                    $content_preview =  $line["content_preview"];
                }

                $id = $line["id"];
                $feed_id = $line["feed_id"];
                $label_cache = $line["label_cache"];
                $labels = false;

                if ($label_cache) {
                    $label_cache = json_decode($label_cache, true);

                    if ($label_cache) {
                        if ($label_cache["no-labels"] == 1) {
                            $labels = array();
                        } else {
                            $labels = $label_cache;
                        }
                    }
                }

                if (!is_array($labels)) {
                    $labels = get_article_labels($id);
                }

                $labels_str = "<span class=\"HLLCTR-$id\">";
                $labels_str .= format_article_labels($labels, $id);
                $labels_str .= "</span>";

                if (count($topmost_article_ids) < 3) {
                    array_push($topmost_article_ids, $id);
                }

                $class = "";

                if (sql_bool_to_bool($line["unread"])) {
                    $class .= " Unread";
                    ++$num_unread;
                }

                if (sql_bool_to_bool($line["marked"])) {
                    $marked_pic = "<img
                        src=\"images/mark_set.png\"
                        class=\"markedPic\" alt=\"Unstar article\"
                        onclick='toggleMark($id)'>";
                    $class .= " marked";
                } else {
                    $marked_pic = "<img
                        src=\"images/mark_unset.png\"
                        class=\"markedPic\" alt=\"Star article\"
                        onclick='toggleMark($id)'>";
                }

                if (sql_bool_to_bool($line["published"])) {
                    $published_pic = "<img src=\"images/pub_set.png\"
                        class=\"pubPic\"
                            alt=\"Unpublish article\" onclick='togglePub($id)'>";
                    $class .= " published";
                } else {
                    $published_pic = "<img src=\"images/pub_unset.png\"
                        class=\"pubPic\"
                        alt=\"Publish article\" onclick='togglePub($id)'>";
                }

                $updated_fmt = make_local_datetime($line["updated"], false);
                $date_entered_fmt = T_sprintf(
                    "Imported at %s",
                    make_local_datetime($line["date_entered"], false)
                );

                $score = $line["score"];

                $score_pic = "images/" . get_score_pic($score);

                $score_pic = "<img class='hlScorePic' score='$score' onclick='changeScore($id, this)' src=\"$score_pic\"
                    title=\"$score\">";

                if ($score > 500) {
                    $hlc_suffix = "high";
                } elseif ($score < -100) {
                    $hlc_suffix = "low";
                } else {
                    $hlc_suffix = "";
                }

                $entry_author = $line["author"];

                if ($entry_author) {
                    $entry_author = " &mdash; $entry_author";
                }

                $has_feed_icon = feed_has_icon($feed_id);

                if ($has_feed_icon) {
                    $feed_icon_img = "<img class=\"tinyFeedIcon\" src=\"".ICONS_URL."/$feed_id.ico\" alt=\"\">";
                } else {
                    $feed_icon_img = "<img class=\"tinyFeedIcon\" src=\"images/pub_set.png\" alt=\"\">";
                }

                $entry_site_url = $line["site_url"];

                //setting feed headline background color, needs to change text color based on dark/light
                $fav_color = $line['favicon_avg_color'];

                require_once "colors.php";

                if ($fav_color && $fav_color != 'fail') {
                    if (!isset($rgba_cache[$feed_id])) {
                        $rgba_cache[$feed_id] = join(",", _color_unpack($fav_color));
                    }
                }

                if (!get_pref('COMBINED_DISPLAY_MODE')) {

                    if ($vfeed_group_enabled) {
                        if ($feed_id != $vgroup_last_feed && $line["feed_title"]) {

                            $cur_feed_title = $line["feed_title"];
                            $vgroup_last_feed = $feed_id;

                            $cur_feed_title = htmlspecialchars($cur_feed_title);

                            $vf_catchup_link = "<a class='catchup' onclick='catchupFeedInGroup($feed_id);' href='#'>".__('mark feed as read')."</a>";

                            $reply['content'] .= "<div id='FTITLE-$feed_id' class='cdmFeedTitle'>".
                                "<div style='float : right'>$feed_icon_img</div>".
                                "<a class='title' href=\"#\" onclick=\"viewfeed($feed_id)\">".                                $line["feed_title"]."</a>
                                $vf_catchup_link</div>";

                        }
                    }

                    $mouseover_attrs = "onmouseover='postMouseIn(event, $id)'
                        onmouseout='postMouseOut($id)'";

                    $reply['content'] .= "<div class='hl $class' orig-feed-id='$feed_id' id='RROW-$id' $mouseover_attrs>";

                    $reply['content'] .= "<div class='hlLeft'>";

                    $reply['content'] .= "<input dojoType=\"dijit.form.CheckBox\"
                            type=\"checkbox\" onclick=\"toggleSelectRow2(this)\"
                            class='rchk'>";

                    $reply['content'] .= $marked_pic;
                    $reply['content'] .= $published_pic;

                    $reply['content'] .= "</div>";

                    $reply['content'] .= "<div onclick='return hlClicked(event, $id)'
                        class=\"hlTitle\"><span class='hlContent $hlc_suffix'>";
                    $reply['content'] .= "<a id=\"RTITLE-$id\" class=\"title $hlc_suffix\"
                        href=\"" . htmlspecialchars($line["link"]) . "\"
                        onclick=\"\">" .
                        truncate_string($line["title"], 200);

                    if (get_pref('SHOW_CONTENT_PREVIEW')) {
                            $reply['content'] .= "<span class=\"contentPreview\">" . $line["content_preview"] . "</span>";
                    }

                    $reply['content'] .= "</a></span>";

                    $reply['content'] .= $labels_str;

                    $reply['content'] .= "</div>";

                    if (!$vfeed_group_enabled) {
                        if (@$line["feed_title"]) {
                            $rgba = @$rgba_cache[$feed_id];

                            $reply['content'] .= "<span class=\"hlFeed\"><a style=\"background : rgba($rgba, 0.3)\" href=\"#\" onclick=\"viewfeed($feed_id)\">".
                                truncate_string($line["feed_title"], 30)."</a></span>";
                        }
                    }

                    $reply['content'] .= "<span class=\"hlUpdated\">";

                    $reply['content'] .= "<div title='$date_entered_fmt'>$updated_fmt</div>
                        </span>";

                    $reply['content'] .= "<div class=\"hlRight\">";

                    $reply['content'] .= $score_pic;

                    if ($line["feed_title"] && !$vfeed_group_enabled) {

                        $reply['content'] .= "<span onclick=\"viewfeed($feed_id)\"
                            style=\"cursor : pointer\"
                            title=\"".htmlspecialchars($line['feed_title'])."\">
                            $feed_icon_img</span>";
                    }

                    $reply['content'] .= "</div>";
                    $reply['content'] .= "</div>";

                } else {

                    if ($line["tag_cache"]) {
                        $tags = explode(",", $line["tag_cache"]);
                    } else {
                        $tags = false;
                    }

                    $line["content"] = sanitize(
                        $line["content"],
                        sql_bool_to_bool($line['hide_images']),
                        false,
                        $entry_site_url,
                        $highlight_words,
                        $line["id"]
                    );

                    foreach (PluginHost::getInstance()->get_hooks(PluginHost::HOOK_RENDER_ARTICLE_CDM) as $p) {
                        $line = $p->hook_render_article_cdm($line);
                    }

                    if ($vfeed_group_enabled && $line["feed_title"]) {
                        if ($feed_id != $vgroup_last_feed) {

                            $cur_feed_title = $line["feed_title"];
                            $vgroup_last_feed = $feed_id;

                            $cur_feed_title = htmlspecialchars($cur_feed_title);

                            $vf_catchup_link = "<a class='catchup' onclick='catchupFeedInGroup($feed_id);' href='#'>".__('mark feed as read')."</a>";

                            $has_feed_icon = feed_has_icon($feed_id);

                            if ($has_feed_icon) {
                                $feed_icon_img = "<img class=\"tinyFeedIcon\" src=\"".ICONS_URL."/$feed_id.ico\" alt=\"\">";
                            } else {
                                //$feed_icon_img = "<img class=\"tinyFeedIcon\" src=\"images/blank_icon.gif\" alt=\"\">";
                            }

                            $reply['content'] .= "<div id='FTITLE-$feed_id' class='cdmFeedTitle'>".
                                "<div style=\"float : right\">$feed_icon_img</div>".
                                "<a href=\"#\" class='title' onclick=\"viewfeed($feed_id)\">".
                                $line["feed_title"]."</a> $vf_catchup_link</div>";
                        }
                    }

                    $mouseover_attrs = "onmouseover='postMouseIn(event, $id)'
                        onmouseout='postMouseOut($id)'";

                    $expanded_class = $expand_cdm ? "expanded" : "expandable";

                    $reply['content'] .= "<div class=\"cdm ".$hlc_suffix." ".$expanded_class." ".$class."\"
                        id=\"RROW-$id\" orig-feed-id='$feed_id' $mouseover_attrs>";

                    $reply['content'] .= "<div class=\"cdmHeader\">";
                    $reply['content'] .= "<div style=\"vertical-align : middle\">";

                    $reply['content'] .= "<input dojoType=\"dijit.form.CheckBox\"
                            type=\"checkbox\" onclick=\"toggleSelectRow2(this, false, true)\"
                            class='rchk'>";

                    $reply['content'] .= $marked_pic;
                    $reply['content'] .= $published_pic;

                    $reply['content'] .= "</div>";

                    if ($highlight_words && count($highlight_words > 0)) {
                        foreach ($highlight_words as $word) {
                            $line["title"] = preg_replace(
                                "/(\Q$word\E)/i",
                                "<span class=\"highlight\">$1</span>",
                                $line["title"]
                            );
                        }
                    }

                    $reply['content'] .= "<span id=\"RTITLE-".$id."\"
                        onclick=\"return cdmClicked(event, ".$id.");\"
                        class=\"titleWrap ".$hlc_suffix."\">
                        <a class=\"title ".$hlc_suffix."\"
                        target=\"_blank\" href=\"".
                        htmlspecialchars($line["link"])."\">".
                        $line["title"] .
                        "</a> <span class=\"author\">".$entry_author."</span>";

                    $reply['content'] .= $labels_str;

                    $reply['content'] .= "<span class='collapseBtn' style='display:none;'>
                        <img src=\"images/collapse.png\" onclick=\"cdmCollapseArticle(event, ".$id.")\"
                        title=\"".__("Collapse article")."\"/></span>";

                    if (!$expand_cdm) {
                        $content_hidden = "style=\"display:none;\"";
                    } else {
                        $excerpt_hidden = "style=\"display:none;\"";
                    }

                    $reply['content'] .= "<span ".$excerpt_hidden .
                        " id=\"CEXC-".$id."\" class=\"cdmExcerpt\">" .
                        $content_preview . "</span>";

                    $reply['content'] .= "</span>";

                    if (!$vfeed_group_enabled && @$line["feed_title"]) {
                        $rgba = @$rgba_cache[$feed_id];

                        $reply['content'] .= "<div class=\"hlFeed\">
                            <a href=\"#\" style=\"background-color: rgba($rgba,0.3)\"
                            onclick=\"viewfeed($feed_id)\">".
                            truncate_string($line["feed_title"], 30)."</a>
                            </div>";
                    }

                    $reply['content'] .= "<span class='updated' title='$date_entered_fmt'>
                        $updated_fmt</span>";

                    $reply['content'] .= "<div class='scoreWrap' style=\"vertical-align : middle\">";
                    $reply['content'] .= "$score_pic";

                    if (!get_pref("VFEED_GROUP_BY_FEED") && $line["feed_title"]) {
                        $reply['content'] .= "<span style=\"cursor : pointer\"
                            title=\"".htmlspecialchars($line["feed_title"])."\"
                            onclick=\"viewfeed($feed_id)\">$feed_icon_img</span>";
                    }
                    $reply['content'] .= "</div>";
                    $reply['content'] .= "</div>";

                    $reply['content'] .= "<div class=\"cdmContent\" $content_hidden
                        onclick=\"return cdmClicked(event, $id);\"
                        id=\"CICD-$id\">";

                    $reply['content'] .= "<div id=\"POSTNOTE-$id\">";
                    if ($line['note']) {
                        $reply['content'] .= format_article_note($id, $line['note']);
                    }
                    $reply['content'] .= "</div>";

                    if (!$line['lang']) {
                        $line['lang'] = 'en';
                    }

                    $reply['content'] .= "<div class=\"cdmContentInner\" lang=\"".$line['lang']."\">";

                    if ($line["orig_feed_id"]) {

                        $tmp_result = $this->dbh->query("SELECT * FROM ttrss_archived_feeds
                            WHERE id = ".$line["orig_feed_id"]);

                        if ($this->dbh->num_rows($tmp_result) != 0) {

                            $reply['content'] .= "<div clear='both'>";
                            $reply['content'] .= __("Originally from:");

                            $reply['content'] .= "&nbsp;";

                            $tmp_line = $this->dbh->fetch_assoc($tmp_result);

                            $reply['content'] .= "<a target='_blank'
                                href=' " . htmlspecialchars($tmp_line['site_url']) . "'>" .
                                $tmp_line['title'] . "</a>";

                            $reply['content'] .= "&nbsp;";

                            $reply['content'] .= "<a target='_blank' href='" . htmlspecialchars($tmp_line['feed_url']) . "'>";
                            $reply['content'] .= "<img title='".__('Feed URL')."'class='tinyFeedIcon' src='images/pub_unset.png'></a>";

                            $reply['content'] .= "</div>";
                        }
                    }

                    $reply['content'] .= "<span id=\"CWRAP-$id\">";

                    $reply['content'] .= "<span id=\"CENCW-$id\" style=\"display : none\">";
                    $reply['content'] .= htmlspecialchars($line["content"]);
                    $reply['content'] .= "</span>";

                    $reply['content'] .= "</span>";

                    $always_display_enclosures = sql_bool_to_bool($line["always_display_enclosures"]);

                    $reply['content'] .= format_article_enclosures($id, $always_display_enclosures, $line["content"], sql_bool_to_bool($line["hide_images"]));

                    $reply['content'] .= "</div>";

                    $reply['content'] .= "<div class=\"cdmFooter\">";

                    foreach (PluginHost::getInstance()->get_hooks(PluginHost::HOOK_ARTICLE_LEFT_BUTTON) as $p) {
                        $reply['content'] .= $p->hook_article_left_button($line);
                    }

                    $tags_str = format_tags_string($tags, $id);

                    $reply['content'] .= "<img src='images/tag.png' alt='Tags' title='Tags'>
                        <span id=\"ATSTR-$id\">$tags_str</span>
                        <a title=\"".__('Edit tags for this article')."\"
                        href=\"#\" onclick=\"editArticleTags($id)\">(+)</a>";

                    $num_comments = $line["num_comments"];
                    $entry_comments = "";

                    if ($num_comments > 0) {
                        if ($line["comments"]) {
                            $comments_url = htmlspecialchars($line["comments"]);
                        } else {
                            $comments_url = htmlspecialchars($line["link"]);
                        }
                        $entry_comments = "<a class=\"postComments\"
                            target='_blank' href=\"$comments_url\">$num_comments ".
                            _ngettext("comment", "comments", $num_comments)."</a>";

                    } elseif ($line["comments"] && $line["link"] != $line["comments"]) {
                        $entry_comments = "<a class=\"postComments\" target='_blank' href=\"".
                            htmlspecialchars($line["comments"])."\">".
                            __("comments")."</a>";
                    }

                    if ($entry_comments) {
                        $reply['content'] .= "&nbsp;($entry_comments)";
                    }

                    $reply['content'] .= "<div style=\"float:right;\">";

                    //$reply['content'] .= "$marked_pic";
                    //$reply['content'] .= "$published_pic";

                    foreach (PluginHost::getInstance()->get_hooks(PluginHost::HOOK_ARTICLE_BUTTON) as $p) {
                        $reply['content'] .= $p->hook_article_button($line);
                    }

                    $reply['content'] .= "</div>";
                    $reply['content'] .= "</div>";
                    $reply['content'] .= "</div>";
                    $reply['content'] .= "</div>";

                }

                ++$lnum;
            }

            if ($_REQUEST["debug"]) {
                $timing_info = print_checkpoint("PE", $timing_info);
            }

        } else {
            $message = "";

            switch ($view_mode) {
                case "unread":
                    $message = __("No unread articles found to display.");
                    break;
                case "updated":
                    $message = __("No updated articles found to display.");
                    break;
                case "marked":
                    $message = __("No starred articles found to display.");
                    break;
                default:
                    if ($feed < LABEL_BASE_INDEX) {
                        $message = __("No articles found to display. You can assign articles to labels manually from article header context menu (applies to all selected articles) or use a filter.");
                    } else {
                        $message = __("No articles found to display.");
                    }
            }

            if (!$offset && $message) {
                $reply['content'] .= "<div class='whiteBox'>$message";
                $reply['content'] .= "<p><span class=\"insensitive\">";

                $result = $this->dbh->query(
                    "SELECT ".SUBSTRING_FOR_DATE."(MAX(last_updated), 1, 19) AS last_updated FROM ttrss_feeds
                    WHERE owner_uid = " . $_SESSION['uid']
                );

                $last_updated = $this->dbh->fetch_result($result, 0, "last_updated");
                $last_updated = make_local_datetime($last_updated, false);

                $reply['content'] .= sprintf(__("Feeds last updated at %s"), $last_updated);

                $result = $this->dbh->query(
                    "SELECT COUNT(id) AS num_errors FROM ttrss_feeds
                    WHERE last_error != '' AND owner_uid = ".$_SESSION["uid"]
                );

                $num_errors = $this->dbh->fetch_result($result, 0, "num_errors");

                if ($num_errors > 0) {
                    $reply['content'] .= "<br/>";
                    $reply['content'] .= "<a class=\"insensitive\" href=\"#\" onclick=\"showFeedsWithErrors()\">".
                        __('Some feeds have update errors (click for details)')."</a>";
                }
                $reply['content'] .= "</span></p></div>";
            }
        }

        if ($_REQUEST["debug"]) {
            $timing_info = print_checkpoint("H2", $timing_info);
        }

        return array($topmost_article_ids, $headlines_count, $feed, $disable_cache,
            $vgroup_last_feed, $reply);
    }

    function catchupAll()
    {
        $this->dbh->query("UPDATE ttrss_user_entries SET
            last_read = NOW(), unread = false WHERE unread = true AND owner_uid = " . $_SESSION["uid"]);
        ccache_zero_all($_SESSION["uid"]);
    }

    function view()
    {
        $timing_info = microtime(true);

        $reply = array();

        if ($_REQUEST["debug"]) {
            $timing_info = print_checkpoint("0", $timing_info);
        }

        $feed = $this->dbh->escape_string($_REQUEST["feed"]);
        $method = $this->dbh->escape_string($_REQUEST["m"]);
        $view_mode = $this->dbh->escape_string($_REQUEST["view_mode"]);
        $limit = 30;
        @$cat_view = $_REQUEST["cat"] == "true";
        @$next_unread_feed = $this->dbh->escape_string($_REQUEST["nuf"]);
        @$offset = $this->dbh->escape_string($_REQUEST["skip"]);
        @$vgroup_last_feed = $this->dbh->escape_string($_REQUEST["vgrlf"]);
        $order_by = $this->dbh->escape_string($_REQUEST["order_by"]);

        if (is_numeric($feed)) {
            $feed = (int) $feed;
        }

        /* Feed -5 is a special case: it is used to display auxiliary information
         * when there's nothing to load - e.g. no stuff in fresh feed */

        if ($feed == -5) {
            print json_encode($this->generate_dashboard_feed());
            return;
        }

        $result = false;

        if ($feed < LABEL_BASE_INDEX) {
            $label_feed = feed_to_label_id($feed);
            $result = $this->dbh->query(
                "SELECT id FROM ttrss_labels2
                WHERE id = '$label_feed' AND owner_uid = " . $_SESSION['uid']
            );
        } elseif (!$cat_view && is_numeric($feed) && $feed > 0) {
            $result = $this->dbh->query(
                "SELECT id FROM ttrss_feeds
                WHERE id = '$feed' AND owner_uid = " . $_SESSION['uid']
            );
        } elseif ($cat_view && is_numeric($feed) && $feed > 0) {
            $result = $this->dbh->query(
                "SELECT id FROM ttrss_feed_categories
                WHERE id = '$feed' AND owner_uid = " . $_SESSION['uid']
            );
        }

        if ($result && $this->dbh->num_rows($result) == 0) {
            print json_encode($this->generate_error_feed(__("Feed not found.")));
            return;
        }

        /* Updating a label ccache means recalculating all of the caches
         * so for performance reasons we don't do that here */

        if ($feed >= 0) {
            ccache_update($feed, $_SESSION["uid"], $cat_view);
        }

        set_pref("_DEFAULT_VIEW_MODE", $view_mode);
        set_pref("_DEFAULT_VIEW_ORDER_BY", $order_by);

        /* bump login timestamp if needed */
        if (time() - $_SESSION["last_login_update"] > 3600) {
            $this->dbh->query(
                "UPDATE ttrss_users SET last_login = NOW() WHERE id = " .
                $_SESSION["uid"]
            );
            $_SESSION["last_login_update"] = time();
        }

        if (!$cat_view && is_numeric($feed) && $feed > 0) {
            $this->dbh->query(
                "UPDATE ttrss_feeds SET last_viewed = NOW()
                WHERE id = '$feed' AND owner_uid = ".$_SESSION["uid"]
            );
        }

        $reply['headlines'] = array();

        if (!$next_unread_feed) {
            $reply['headlines']['id'] = $feed;
        } else {
            $reply['headlines']['id'] = $next_unread_feed;
        }

        $reply['headlines']['is_cat'] = (bool) $cat_view;

        $override_order = false;

        switch ($order_by) {
            case "title":
                $override_order = "ttrss_entries.title";
                break;
            case "date_reverse":
                $override_order = "score DESC, date_entered, updated";
                break;
            case "feed_dates":
                $override_order = "updated DESC";
                break;
        }

        if ($_REQUEST["debug"]) {
            $timing_info = print_checkpoint("04", $timing_info);
        }

        $ret = $this->format_headlines_list(
            $feed,
            $method,
            $view_mode,
            $limit,
            $cat_view,
            $next_unread_feed,
            $offset,
            $vgroup_last_feed,
            $override_order,
            true
        );

        //$topmost_article_ids = $ret[0];
        $headlines_count = $ret[1];
        /* $returned_feed = $ret[2]; */
        $disable_cache = $ret[3];
        $vgroup_last_feed = $ret[4];

        $reply['headlines']['content'] =& $ret[5]['content'];
        $reply['headlines']['toolbar'] =& $ret[5]['toolbar'];

        if ($_REQUEST["debug"]) {
            $timing_info = print_checkpoint("05", $timing_info);
        }

        $reply['headlines-info'] = array("count" => (int) $headlines_count,
                        "vgroup_last_feed" => $vgroup_last_feed,
                        "disable_cache" => (bool) $disable_cache);

        if ($_REQUEST["debug"]) {
            $timing_info = print_checkpoint("30", $timing_info);
        }

        $reply['runtime-info'] = make_runtime_info();

        print json_encode($reply);

    }

    private function generate_dashboard_feed()
    {
        $reply = array();

        $reply['headlines']['id'] = -5;
        $reply['headlines']['is_cat'] = false;

        $reply['headlines']['toolbar'] = '';
        $reply['headlines']['content'] = "<div class='whiteBox'>".__('No feed selected.');

        $reply['headlines']['content'] .= "<p><span class=\"insensitive\">";

        $result = $this->dbh->query("SELECT ".SUBSTRING_FOR_DATE."(MAX(last_updated), 1, 19) AS last_updated FROM ttrss_feeds
            WHERE owner_uid = " . $_SESSION['uid']);

        $last_updated = $this->dbh->fetch_result($result, 0, "last_updated");
        $last_updated = make_local_datetime($last_updated, false);

        $reply['headlines']['content'] .= sprintf(__("Feeds last updated at %s"), $last_updated);

        $result = $this->dbh->query("SELECT COUNT(id) AS num_errors
            FROM ttrss_feeds WHERE last_error != '' AND owner_uid = ".$_SESSION["uid"]);

        $num_errors = $this->dbh->fetch_result($result, 0, "num_errors");

        if ($num_errors > 0) {
            $reply['headlines']['content'] .= "<br/>";
            $reply['headlines']['content'] .= "<a class=\"insensitive\" href=\"#\" onclick=\"showFeedsWithErrors()\">".
                __('Some feeds have update errors (click for details)')."</a>";
        }
        $reply['headlines']['content'] .= "</span></p>";

        $reply['headlines-info'] = array("count" => 0,
            "vgroup_last_feed" => '',
            "unread" => 0,
            "disable_cache" => true);

        return $reply;
    }

    private function generate_error_feed($error)
    {
        $reply = array();

        $reply['headlines']['id'] = -6;
        $reply['headlines']['is_cat'] = false;

        $reply['headlines']['toolbar'] = '';
        $reply['headlines']['content'] = "<div class='whiteBox'>". $error . "</div>";

        $reply['headlines-info'] = array("count" => 0,
            "vgroup_last_feed" => '',
            "unread" => 0,
            "disable_cache" => true);

        return $reply;
    }

    function quickAddFeed()
    {
        // Initialize template engine
        $loader = new Twig_Loader_Filesystem('templates/html');
        $twig = new Twig_Environment($loader, array('cache' => 'cache/templates'));

        // Load template
        $template = $twig->loadTemplate('classes/feeds/quickAddFeed.html');
        $template_vars = array();

        // Fill template variables
        if (get_pref('ENABLE_FEED_CATS')) {
            $template_vars['cats'] = true;
            $template_vars['feed_cat_select'] = return_feed_cat_select(
                "cat",
                false,
                'dojoType="dijit.form.Select"'
            );
        }

        if (!defined('_DISABLE_FEED_BROWSER') || !_DISABLE_FEED_BROWSER) {
            $template_vars['feedbrowser'] = true;
        }

        // Render the template
        echo $template->render($template_vars);
    }

    function feedBrowser()
    {
        if (defined('_DISABLE_FEED_BROWSER') && _DISABLE_FEED_BROWSER) {
            return;
        }

        // Initialize template engine
        $loader = new Twig_Loader_Filesystem('templates/html/classes/feeds');
        $twig = new Twig_Environment($loader, array('cache' => 'cache/templates'));

        // Load template
        $template = $twig->loadTemplate('feedBrowser.html');
        $template_vars = array();

        // Fill template variables
        $template_vars['browser_search'] = $this->dbh->escape_string($_REQUEST["search"]);

        require_once "feedbrowser.php";
        $template_vars['feed_list'] = make_feed_browser("", 25);

        // Render the template
        echo $template->render($template_vars);
    }

    function search()
    {
        // Initialize template engine
        $loader = new Twig_Loader_Filesystem('templates/html/classes/feeds');
        $twig = new Twig_Environment($loader, array('cache' => 'cache/templates'));

        // Load template
        $template = $twig->loadTemplate('search.html');
        $template_vars = array();

        // Fill template variables
        $this->params = explode(":", $this->dbh->escape_string($_REQUEST["param"]), 2);

        $active_feed_id = sprintf("%d", $this->params[0]);
        $is_cat = $this->params[1] != "false";

        if ($active_feed_id && !$is_cat) {
            $template_vars['feed_title'] = getFeedTitle($active_feed_id);
        } else {
            $template_vars['this_feed'] = true;
        }

        if (get_pref('ENABLE_FEED_CATS') && ($active_feed_id > 0 || $is_cat)) {
            $template_vars['this_cat'] = true;

            if ($is_cat) {
                $template_vars['is_cat'] = true;
                $template_vars['feed_cat_title'] = getCategoryTitle($active_feed_id);
            } else {
                $template_vars['is_cat'] = false;
                $template_vars['feed_cat_title'] = getFeedCatTitle($active_feed_id);
            }
        }

        if (count(PluginHost::getInstance()->get_hooks(PluginHost::HOOK_SEARCH)) == 0) {
            $template_vars['syntax'] = true;
        }

        // Render the template
        echo $template->render($template_vars);
    }
}
