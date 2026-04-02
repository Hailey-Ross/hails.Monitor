// Script Created by Hailey Enfield
// Site: https://u.hails.cc/Links
// Github: https://github.com/Hailey-Ross/hails.Monitor
// PLEASE LEAVE ALL CREDITS/COMMENTS INTACT
// Scans the entire sim, stores avatars with detection timestamps and region
// Say "hails info" in public chat for Command List

list allowed_users = ["11111111-2222-3333-4444-555555555555","aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"]; // Who else can use public commands? UUID's only
integer scan_interval = 5; // How often to scan
integer command_channel = 2; // IM Toggle command channel
integer batch_size = 20; // Number of avatars to send in each batch

// Database Connection strings
string server_url = "https://YOUR-SITE-HERE.tld/av.php"; // Secure HTTPS URL
string API_KEY = "YOUR-API-KEY-HERE"; // API Key for server communication

// DO NOT TOUCH BELOW HERE
list avatar_list = [];         // Active avatars currently detected in-region only: [uuid, name, first_seen, last_seen]
float last_notification_time = 0.0; 
integer waiting_for_response = FALSE;
integer debug_enabled = FALSE;
integer im_notifications_enabled = FALSE;
integer notification_cooldown = 60;
integer scanner_active = FALSE;
integer notify_active = TRUE;
integer heartbeat_interval = 30;     // seconds
integer scanner_timeout = 90;        // must be greater than heartbeat_interval
integer last_heartbeat_sent = 0;
string scanner_key = "";
string active_region = "";
string scanner_name = "hails.Monitor-Beta v0.0.9a";
string prim_name = "hails.Monitor";

debug(string message) {
    if (debug_enabled) {
        llOwnerSay(message);
    }
}

// Function to send avatar data in batches to the server
sendBatchToServer() {
    if (llGetListLength(avatar_list) == 0) {
        return;
    }

    integer count = llGetListLength(avatar_list);
    integer i;
    string post_prefix = "api_key=" + API_KEY + "&action=store_batch&data=";
    string region_name = llGetRegionName();

    list batch = [];
    integer batch_avatar_count = 0;

    for (i = 0; i < count; i += 4) {
        string avatar_key = llList2String(avatar_list, i);
        string avatar_name = llList2String(avatar_list, i + 1);
        string first_seen = llList2String(avatar_list, i + 2);
        string last_seen = llList2String(avatar_list, i + 3);

        batch += [avatar_name, avatar_key, region_name, first_seen, last_seen];
        batch_avatar_count++;

        if (batch_avatar_count >= batch_size) {
            string post_data = post_prefix + llDumpList2String(batch, ",");
            debug("Sending batch to server. Avatar count: " + (string)batch_avatar_count);
            llHTTPRequest(server_url, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"], post_data);
            batch = [];
            batch_avatar_count = 0;
        }
    }

    if (llGetListLength(batch) > 0) {
        string post_data = post_prefix + llDumpList2String(batch, ",");
        debug("Sending batch to server. Avatar count: " + (string)batch_avatar_count);
        llHTTPRequest(server_url, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"], post_data);
    }
}

checkInRegion() {
    active_region = llGetRegionName();
    string object_name = llGetObjectName();

    string post_data =
        "api_key=" + API_KEY +
        "&action=scanner_checkin" +
        "&region_name=" + llEscapeURL(active_region) +
        "&scanner_key=" + llEscapeURL(scanner_key) +
        "&owner_key=" + llEscapeURL((string)llGetOwner()) +
        "&object_name=" + llEscapeURL(object_name) +
        "&timeout_seconds=" + (string)scanner_timeout;

    llHTTPRequest(
        server_url,
        [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"],
        post_data
    );
}

releaseRegion() {
    if (active_region == "" || scanner_key == "") {
        return;
    }

    string post_data =
        "api_key=" + API_KEY +
        "&action=scanner_release" +
        "&region_name=" + llEscapeURL(active_region) +
        "&scanner_key=" + llEscapeURL(scanner_key);

    llHTTPRequest(
        server_url,
        [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"],
        post_data
    );
}

default {
    state_entry() {
        scanner_key = (string)llGetKey();
        active_region = llGetRegionName();
        scanner_active = FALSE;
        last_heartbeat_sent = 0;
        llSetObjectName(prim_name);
    
        llOwnerSay(scanner_name + " starting in region: " + active_region);
        checkInRegion();
    
        llSetTimerEvent(scan_interval);
        llListen(0, "", llGetOwner(), "");
        llListen(command_channel, "", llGetOwner(), "");
    }

    on_rez(integer start_param) {
        llSetObjectName(prim_name);
        llResetScript();
    }
    
    changed(integer change) {
        if (change & CHANGED_REGION) {
            releaseRegion();

            avatar_list = [];
            last_heartbeat_sent = 0;
            scanner_key = "";
            llSetObjectName(prim_name);

            active_region = llGetRegionName();
            scanner_active = FALSE;
            checkInRegion();
        }

        if (change & CHANGED_OWNER) {
            releaseRegion();
            notify_active = TRUE;
            llResetScript();
        }
    }

    timer() {
        integer now = llGetUnixTime();

        if ((now - last_heartbeat_sent) >= heartbeat_interval) {
            last_heartbeat_sent = now;
            checkInRegion();
        }

        if (!scanner_active) {
            debug("Scanner is inactive in this region. Skipping scan.");
            return;
        }

        list agents = llGetAgentList(AGENT_LIST_REGION, []);
        integer count = llGetListLength(agents);

        debug("Timer event fired. Number of agents detected: " + (string)count);

        integer i;
        string now_ts = llDeleteSubString(llGetTimestamp(), -4, -1);

        integer list_count = llGetListLength(avatar_list);
        for (i = list_count - 4; i >= 0; i -= 4) {
            string stored_uuid = llList2String(avatar_list, i);

            if (llListFindList(agents, [(key)stored_uuid]) == -1) {
                avatar_list = llDeleteSubList(avatar_list, i, i + 3);
            }
        }

        for (i = 0; i < count; i++) {
            key avatar_key = llList2Key(agents, i);
            string avatar_uuid = (string)avatar_key;
            integer row_start = -1;
            integer j;
            integer current_count = llGetListLength(avatar_list);

            for (j = 0; j < current_count; j += 4) {
                if (llList2String(avatar_list, j) == avatar_uuid) {
                    row_start = j;
                    j = current_count;
                }
            }

            integer debug_index;

            if (row_start == -1) {
                debug_index = -1;
            } else {
                debug_index = row_start / 4;
            }
            
            // debug("Avatar detected: UUID: " + avatar_uuid + ", Index: " + (string)debug_index);

            if (row_start == -1) {
                string avatar_name = llKey2Name(avatar_key);

                avatar_list += [avatar_uuid, avatar_name, now_ts, now_ts];

                if (im_notifications_enabled && (llGetTime() - last_notification_time) > notification_cooldown) {
                    llInstantMessage(llGetOwner(), "New Visitor detected: " + avatar_name + " (UUID: " + avatar_uuid + ")");
                    last_notification_time = llGetTime();
                }
            } else {
                string avatar_name = llList2String(avatar_list, row_start + 1);
                avatar_list = llListReplaceList(avatar_list, [now_ts], row_start + 3, row_start + 3);

                if (im_notifications_enabled && (llGetTime() - last_notification_time) > notification_cooldown) {
                    llInstantMessage(llGetOwner(), "Visitor updated: " + avatar_name + " (UUID: " + avatar_uuid + ")");
                    last_notification_time = llGetTime();
                }
            }
        }

        sendBatchToServer();
    }

    listen(integer channel, string name, key id, string message) {
        message = llToLower(message);

        if (channel == command_channel && id == llGetOwner()) {
            if (message == "toggle im") {
                im_notifications_enabled = !im_notifications_enabled;
                if (im_notifications_enabled) {
                    llOwnerSay("IM notifications are now enabled.");
                } else {
                    llOwnerSay("IM notifications are now disabled.");
                }
            } else if (message == "toggle debug") {
                debug_enabled = !debug_enabled;
                if (debug_enabled) {
                    llOwnerSay("Debugging is now enabled.");
                } else {
                    llOwnerSay("Debugging is now disabled.");
                }
            }
        } else if (channel == 0 && (id == llGetOwner() || llListFindList(allowed_users, [id]) != -1)) {
            if (message == "hails reset") {
                avatar_list = [];
                llInstantMessage(id, "Rebooting " + scanner_name + "..");
                llResetScript();
            } else if (message == "hails info") {
                llInstantMessage(id,
                    scanner_name + " Commands:\n" +
                    "• 'hails reset' - Resets the script.\n" +
                    "• '/"
                    + (string)command_channel + " toggle im' - (Owner Only) Toggles IM notifications for new avatar detection.\n" +
                    "• '/"
                    + (string)command_channel + " toggle debug' - (Owner Only) Toggles debugging output."
                );
            }
        } else {
            llInstantMessage(id, "Access denied.");
        }
    }

    http_response(key request_id, integer status, list metadata, string body) {
        debug("HTTP Response Status: " + (string)status);
        debug("Server response: " + body);
    
        if (status != 200) {
            debug("HTTP request failed.");
            return;
        }
    
        string lower_body = llToLower(body);
    
        if (llSubStringIndex(lower_body, "\"is_active\":1") != -1) {
            if (notify_active) {
                llOwnerSay(scanner_name + " is now ACTIVE in region " + active_region + ".");
                llSetObjectDesc(active_region + " Server");
            }
            scanner_active = TRUE;
            notify_active = FALSE;
        } else if (llSubStringIndex(lower_body, "\"is_active\":0") != -1) {
            if (notify_active) {
                llOwnerSay(scanner_name + " is now INACTIVE in region " + active_region + ", due to another scanner already being active.");
                llSetObjectDesc("Not currently activated in this Sim.");
            }
            scanner_active = FALSE;
            notify_active = FALSE;
        } else if (llSubStringIndex(lower_body, "batch update completed successfully") != -1) {
        } else if (llSubStringIndex(lower_body, "scanner_release") != -1) {
        } else {
            debug("No scanner status in response; leaving current active state unchanged.");
        }
    
        waiting_for_response = FALSE;
    }
}
