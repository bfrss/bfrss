<?php
function make_feed_browser($search, $limit, $mode = 1)
{
    if ($mode != 1 && $mode != 2) {
        return "";
    }

    $owner_uid = $_SESSION["uid"];

    if ($search) {
        $search_qpart = "AND (UPPER(feed_url) LIKE UPPER('%$search%') OR
                    UPPER(title) LIKE UPPER('%$search%'))";
    } else {
        $search_qpart = "";
    }

    if ($mode == 1) {
        $result = db_query(
            "SELECT feed_url, site_url, title, SUM(subscribers) AS subscribers FROM
                (SELECT feed_url, site_url, title, subscribers FROM ttrss_feedbrowser_cache UNION ALL
                    SELECT feed_url, site_url, title, subscribers FROM ttrss_linked_feeds) AS qqq
                WHERE
                    (SELECT COUNT(id) = 0 FROM ttrss_feeds AS tf
                        WHERE tf.feed_url = qqq.feed_url
                            AND owner_uid = '$owner_uid') $search_qpart
                GROUP BY feed_url, site_url, title
                ORDER BY subscribers DESC LIMIT $limit"
        );

    } elseif ($mode == 2) {
        $result = db_query(
            "SELECT *,
                (SELECT COUNT(*) FROM ttrss_user_entries WHERE
                    orig_feed_id = ttrss_archived_feeds.id) AS articles_archived
                FROM
                    ttrss_archived_feeds
                WHERE
                (SELECT COUNT(*) FROM ttrss_feeds
                    WHERE ttrss_feeds.feed_url = ttrss_archived_feeds.feed_url AND
                        owner_uid = '$owner_uid') = 0    AND
                owner_uid = '$owner_uid' $search_qpart
                ORDER BY id DESC LIMIT $limit"
        );
    }

    $rv = '';
    $feedctr = 0;

    while ($line = db_fetch_assoc($result)) {

        $feed_url = htmlspecialchars($line['feed_url']);
        $site_url = htmlspecialchars($line['site_url']);

        $check_box = "<input onclick='toggleSelectListRow2(this)'
            dojoType=\"dijit.form.CheckBox\" type=\"checkbox\">";

        $class = ($feedctr % 2) ? "even" : "odd";

        $site_url_tag = "<a target=\"_blank\" href=\"".$site_url."\">
            <span class=\"fb_feedTitle\">".
            htmlspecialchars($line['title']).
            "</span></a>";

        $feed_url_tag = "<a target=\"_blank\" class=\"fb_feedUrl\"
            href=\"".$feed_url."\"><img src='images/pub_set.png'
            style='vertical-align:middle;'></a>";

        if ($mode == 1) {
            $id = "";
            $count = $line['subscribers'];

        } elseif ($mode == 2) {
            $id = " id=\"FBROW-".$line["id"]."\"";
            if ($line['articles_archived'] > 0) {
                $archived = sprintf(
                    _ngettext("%d archived article", "%d archived articles", $line['articles_archived']),
                    $line['articles_archived']
                );
                $count = "(".$archived.")";
            } else {
                $count = '';
            }
        }

        $rv .= "<li".$id.">".
            $check_box." ".$feed_url_tag." ".$site_url_tag.
            "&nbsp;<span class='subscribers'>".$count."</span></li>";

        ++$feedctr;
    }

    if ($feedctr === 0) {
        $rv .= "<li style=\"text-align:center;\"><p>".__('No feeds found.')."</p></li>";
    }

    return $rv;
}
