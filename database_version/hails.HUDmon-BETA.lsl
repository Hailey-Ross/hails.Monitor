// Script Created by Hailey Enfield
// Site: https://u.hails.cc/Links
// Github: https://github.com/Hailey-Ross/hails.Monitor
// PLEASE LEAVE ALL CREDITS/COMMENTS INTACT
// Scans the entire sim, stores avatars with detection timestamps and region
// Say "hails info" in public chat for Command List

list allowed_users = ["0fc458f0-50c4-4d6f-95a6-965be6e977ad"]; // Who else can check the visitor list? UUID's only
integer scan_interval = 12; // How often to scan
integer command_channel = 2; // IM Toggle command channel
integer max_avatar_count = 250; // Maximum number of visitors to output
integer batch_size = 20; // Number of avatars to send in each batch

// Database Connection strings
string server_url = "https://YOUR-SITE-URL-HERE.tld/av.php"; // Secure HTTPS URL
string API_KEY = "YOUR-API-KEY-HERE"; // API Key for server communication

// DO NOT TOUCH BELOW HERE
list avatar_list = [];
integer total_visitor_count = 0;
integer waiting_for_response = FALSE;
integer debug_enabled = FALSE;
integer im_notifications_enabled = FALSE;
integer notification_cooldown = 60;

integer scanner_active = FALSE;
integer heartbeat_interval = 30;
integer scanner_timeout = 90;
integer last_heartbeat_sent = 0;

string scanner_key = "";
string active_region = "";
string scanner_name = "hails.HUDMonitor";

float last_notification_time = 0.0;

// Texture UUID
string texture_uuid = "b18295e3-facb-0a25-61ae-d0b49073ea65"; // Set texture UUID

debug(string message) {
    if (debug_enabled) {
        llOwnerSay(message);
    }
}

checkInRegion() {
    active_region = llGetRegionName();

    string post_data =
        "api_key=" + llEscapeURL(API_KEY) +
        "&action=scanner_checkin" +
        "&region_name=" + llEscapeURL(active_region) +
        "&scanner_key=" + llEscapeURL(scanner_key) +
        "&owner_key=" + llEscapeURL((string)llGetOwner()) +
        "&object_name=" + llEscapeURL(llGetObjectName()) +
        "&timeout_seconds=" + (string)scanner_timeout;
        
    string censored_post_data =
        "api_key=CENSORED-API-KEY" +
        "&action=scanner_release" +
        "&region_name=" + llEscapeURL(active_region) +
        "&scanner_key=" + llEscapeURL(scanner_key);

    debug("Sending scanner check-in for region: " + active_region);
    debug("Sending check-in request to server with data: " + censored_post_data);

    llHTTPRequest(
        server_url,
        [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"],post_data);
}

releaseRegion() {
    if (active_region == "" || scanner_key == "") {
        return;
    }

    string post_data =
        "api_key=" + llEscapeURL(API_KEY) +
        "&action=scanner_release" +
        "&region_name=" + llEscapeURL(active_region) +
        "&scanner_key=" + llEscapeURL(scanner_key);
        
    string censored_post_data =
        "api_key=CENSORED-API-KEY" +
        "&action=scanner_release" +
        "&region_name=" + llEscapeURL(active_region) +
        "&scanner_key=" + llEscapeURL(scanner_key);

    debug("Releasing scanner lock for region: " + active_region);
    debug("Sending release request to server with data: " + censored_post_data);

    llHTTPRequest(server_url,[HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"],post_data);
}

sendBatchToServer() {
    if (llGetListLength(avatar_list) == 0) {
        return;
    }

    string post_data = "api_key=" + llEscapeURL(API_KEY) + "&action=store_batch&data=" + llEscapeURL(llDumpList2String(avatar_list, ","));
    string censored_post_data = "api_key=CENSORED_API_KEY&action=store_batch&data=" + llDumpList2String(avatar_list, ",");

    debug("Sending batch to server with data: " + censored_post_data);

    waiting_for_response = TRUE;
    llHTTPRequest(server_url,[HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"],post_data);

    avatar_list = [];
}

default {
    on_rez(integer start_param) {
        llResetScript();
    }

    attach(key id) {
        if (id == NULL_KEY) {
            releaseRegion();
        }
    }

    changed(integer change) {
        if (change & CHANGED_REGION) {
            releaseRegion();
            llOwnerSay(scanner_name + " has detected a region change.\nRebooting..");
            llResetScript();
        }

        if (change & (CHANGED_OWNER | CHANGED_INVENTORY)) {
            releaseRegion();
            llOwnerSay(scanner_name + " has detected a change.\nRebooting..");
            llResetScript();
        }
    }

    state_entry() {
        scanner_key = (string)llGetKey();
        active_region = llGetRegionName();
        scanner_active = FALSE;
        last_heartbeat_sent = 0;
        avatar_list = [];
        total_visitor_count = 0;

        llSetTexture(texture_uuid, ALL_SIDES);
        llSetColor(<1.0, 0.0, 0.5>, ALL_SIDES);

        llOwnerSay(scanner_name + " starting in region: " + active_region);
        checkInRegion();

        if (im_notifications_enabled) {
            llOwnerSay(scanner_name + " is online.\nIM notifications are enabled.");
        } else {
            llOwnerSay(scanner_name + " is online.\nIM notifications are disabled.");
        }

        llSetTimerEvent((float)scan_interval);
        llListen(0, "", llGetOwner(), "");
        llListen(command_channel, "", llGetOwner(), "");
    }

    timer() {
        integer now = llGetUnixTime();

        if ((now - last_heartbeat_sent) >= heartbeat_interval) {
            last_heartbeat_sent = now;
            checkInRegion();
        }

        if (!scanner_active) {
            debug("Scanner is inactive in this region. Skipping scan.");
            llSetColor(<1.0, 0.0, 0.5>, ALL_SIDES);
            return;
        }

        llSetColor(<1.0, 1.0, 1.0>, ALL_SIDES);

        list agents = llGetAgentList(AGENT_LIST_REGION, []);
        integer count = llGetListLength(agents);
        string region_name = llGetRegionName();

        debug("Timer event fired. Number of agents detected: " + (string)count);

        if (count == 0) {
            return;
        }

        integer i;
        for (i = 0; i < count; i++) {
            key avatar_key = llList2Key(agents, i);
            string avatar_name = llKey2Name(avatar_key);

            string first_seen = llDeleteSubString(llGetTimestamp(), -4, -1);
            string last_seen = llDeleteSubString(llGetTimestamp(), -4, -1);

            integer index = llListFindList(avatar_list, [avatar_name, (string)avatar_key]);
            debug("Avatar detected: " + avatar_name + ", UUID: " + (string)avatar_key + ", Index: " + (string)index);

            if (index == -1) {
                avatar_list += [avatar_name, (string)avatar_key, region_name, first_seen, last_seen];
                total_visitor_count++;

                if (llGetListLength(avatar_list) >= batch_size * 5) {
                    sendBatchToServer();
                }

                if (im_notifications_enabled && (llGetTime() - last_notification_time) > notification_cooldown) {
                    llInstantMessage(llGetOwner(), "New Visitor detected: " + avatar_name + " (UUID: " + (string)avatar_key + ")");
                    last_notification_time = llGetTime();
                }
            } else {
                avatar_list = llListReplaceList(avatar_list, [last_seen], index + 4, index + 4);
                debug("Updated last seen time for: " + avatar_name);

                if (im_notifications_enabled && (llGetTime() - last_notification_time) > notification_cooldown) {
                    llInstantMessage(llGetOwner(), "Visitor updated: " + avatar_name + " (UUID: " + (string)avatar_key + ")");
                    last_notification_time = llGetTime();
                }
            }
        }

        if (llGetListLength(avatar_list) > 0) {
            sendBatchToServer();
        }
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
        } else if (channel == 0 && (id == llGetOwner() || llListFindList(allowed_users, [(string)id]) != -1)) {
            if (message == "hails reset") {
                avatar_list = [];
                total_visitor_count = 0;
                releaseRegion();
                llInstantMessage(id, "Rebooting " + scanner_name + "..");
                llResetScript();
            } else if (message == "hails info") {
                llInstantMessage(id,
                    scanner_name + " Commands:\n" +
                    "• 'hails reset' - Resets the script.\n" +
                    "• '/" + (string)command_channel + " toggle im' - (Owner Only) Toggles IM notifications for new avatar detection.\n" +
                    "• '/" + (string)command_channel + " toggle debug' - (Owner Only) Toggles debugging output."
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
            debug("Error fetching avatar data from the server. Status: " + (string)status);
            waiting_for_response = FALSE;
            return;
        }

        string lower_body = llToLower(body);

        if (llSubStringIndex(lower_body, "\"is_active\":1") != -1) {
            if (!scanner_active) {
                scanner_active = TRUE;
                llOwnerSay(scanner_name + " is now ACTIVE in region " + active_region + ".");
            }
            llSetColor(<1.0, 1.0, 1.0>, ALL_SIDES);
        } else if (llSubStringIndex(lower_body, "\"is_active\":0") != -1) {
            if (scanner_active) {
                llOwnerSay(scanner_name + " is now INACTIVE in region " + active_region + " because another scanner is active.");
            }
            scanner_active = FALSE;
            llSetColor(<1.0, 0.0, 0.5>, ALL_SIDES);
        }

        waiting_for_response = FALSE;
    }
}
