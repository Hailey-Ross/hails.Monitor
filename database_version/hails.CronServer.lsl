// Script by Hailey Enfield
list allowed_users = ["00000000-0000-0000-0000-000000000000", "00000000-0000-0000-0000-000000000000"];
string server_url = "https://YOUR-SITE-HERE.tld/avCron.php";
string API_KEY = "YOUR-API-KEY";
integer update_interval = 7200; // Interval between checks (2 hours)
integer debug_enabled = FALSE; // Toggle debug with commands so it doesn't annoy you on restarts.
integer command_channel = 3;
list pending_keys;
string current_avatar_key;
integer expecting_keys = FALSE;
integer delay_update_interval = 5;
integer batch_limit = 5;
integer waiting_for_next_cycle = FALSE;
integer stale_days = 90; // Re-check avatars not seen for this many days
integer verify_days = 150; // Only re-verify stale names this often
integer max_names_per_cycle = 45; // Stop after this many lookups, then wait for next timer cycle
integer names_processed_this_cycle = 0;

string last_lookup_key = "";
key last_lookup_request;

integer isValidAvatarKey(string candidate) {
    candidate = llStringTrim(candidate, STRING_TRIM);
    if (candidate == "") {
        return FALSE;
    }
    if ((key)candidate == NULL_KEY) {
        return FALSE;
    }
    return TRUE;
}

debug(string message) {
    if (debug_enabled) {
        llOwnerSay(message);
    }
}

startWaitingForNextCycle(string reason) {
    pending_keys = [];
    expecting_keys = FALSE;
    waiting_for_next_cycle = TRUE;
    debug(reason);
    llSetTimerEvent(update_interval);
}

performCheck() {
    if (waiting_for_next_cycle == TRUE) {
        debug("Currently waiting for next cycle. Skipping check.");
        return;
    }

    if (names_processed_this_cycle >= max_names_per_cycle) {
        startWaitingForNextCycle(
            "Processed " + (string)names_processed_this_cycle
            + " names this cycle. Sleeping until next interval."
        );
        return;
    }

    string post_data = "api_key=" + API_KEY
        + "&action=check_name_candidates"
        + "&limit=" + (string)batch_limit
        + "&stale_days=" + (string)stale_days
        + "&verify_days=" + (string)verify_days;

    debug("Requesting up to " + (string)batch_limit + " avatar names needing fill or verification...");
    expecting_keys = TRUE;
    llHTTPRequest(server_url, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"], post_data);
}

requestNextLookup() {
    if (names_processed_this_cycle >= max_names_per_cycle) {
        startWaitingForNextCycle(
            "Reached per-cycle cap of " + (string)max_names_per_cycle
            + " names. Sleeping until next interval."
        );
        return;
    }

    if (llGetListLength(pending_keys) > 0) {
        current_avatar_key = llStringTrim(llList2String(pending_keys, 0), STRING_TRIM);
        pending_keys = llDeleteSubList(pending_keys, 0, 0);

        if (!isValidAvatarKey(current_avatar_key)) {
            debug("Skipping invalid UUID entry: " + current_avatar_key);
            llSetTimerEvent(delay_update_interval);
            return;
        }

        debug("Looking up avatar name for UUID: " + current_avatar_key);
        last_lookup_key = current_avatar_key;
        last_lookup_request = llRequestAgentData(current_avatar_key, DATA_NAME);
        names_processed_this_cycle += 1;
        llSetTimerEvent(delay_update_interval);
        return;
    }

    debug("Batch complete; checking for more names needing verification.");
    performCheck();
}

default {
    state_entry() {
        names_processed_this_cycle = 0;
        llSetTimerEvent(update_interval);
        llListen(command_channel, "", llGetOwner(), "");
        llOwnerSay("Hails.CronServer is now online");
        debug("Debugging is enabled.");
        performCheck();
    }

    timer() {
        if (waiting_for_next_cycle == TRUE) {
            waiting_for_next_cycle = FALSE;
            names_processed_this_cycle = 0;
            debug("Starting a new verification cycle.");
            performCheck();
            return;
        }

        requestNextLookup();
    }

    http_response(key request_id, integer status, list metadata, string body) {
        debug("HTTP Response Status: " + (string)status);
        debug("JSON Received: " + body);

        if (status != 200) {
            debug("Error fetching data from server. Status: " + (string)status);
            startWaitingForNextCycle("Sleeping until next interval due to HTTP error.");
            return;
        }

        if (expecting_keys == TRUE) {
            expecting_keys = FALSE;

            if (llSubStringIndex(body, "\"error\"") != -1) {
                debug("Server returned an error: " + body);
                startWaitingForNextCycle("Sleeping until next interval due to server error.");
                return;
            }

            integer start_index = llSubStringIndex(body, "[");
            integer end_index = llSubStringIndex(body, "]");

            if (start_index != -1 && end_index != -1 && end_index > start_index) {
                string clean_body = llGetSubString(body, start_index + 1, end_index - 1);
                list raw_keys = llParseString2List(clean_body, ["\"", ","], []);

                integer i;
                list filtered_keys = [];
                integer count = llGetListLength(raw_keys);

                for (i = 0; i < count; ++i) {
                    string candidate = llStringTrim(llList2String(raw_keys, i), STRING_TRIM);
                    if (isValidAvatarKey(candidate)) {
                        filtered_keys += [candidate];
                    }
                }

                pending_keys = filtered_keys;
                debug("Parsed keys to process: " + llDumpList2String(pending_keys, ", "));

                if (llGetListLength(pending_keys) > 0) {
                    llSetTimerEvent(delay_update_interval);
                } else {
                    startWaitingForNextCycle("No entries found after parsing; sleeping for next interval.");
                }
            } else {
                debug("Error parsing JSON response: invalid indices.");
                startWaitingForNextCycle("Sleeping until next interval due to parse error.");
            }

            return;
        }

        if (llSubStringIndex(body, "\"success\":\"Avatar name updated successfully\"") != -1
            || llSubStringIndex(body, "\"success\":\"Avatar name already current\"") != -1) {
            debug("Avatar name update acknowledged by server.");
            return;
        }

        if (llSubStringIndex(body, "\"error\"") != -1) {
            debug("Server returned an error: " + body);
            return;
        }

        debug("Unexpected response from server: " + body);
    }

    dataserver(key query_id, string avatar_name) {
        if (query_id != last_lookup_request) {
            return;
        }

        if (llStringLength(avatar_name) > 0 && isValidAvatarKey(last_lookup_key)) {
            string post_data = "api_key=" + API_KEY
                + "&action=update_avatar_name"
                + "&avatar_key=" + last_lookup_key
                + "&avatar_name=" + llEscapeURL(avatar_name)
                + "&verify_days=" + (string)verify_days;
            string censored_post_data = "api_key=CENSORED-API-KEY&action=update_avatar_name&avatar_key=" + last_lookup_key + "&avatar_name=" + llEscapeURL(avatar_name) + "&verify_days=" + (string)verify_days;
            debug("Sending to server: " + censored_post_data);
            llHTTPRequest(server_url, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"], post_data);
        } else {
            debug("Avatar name lookup failed for UUID: " + last_lookup_key);
        }
    }

    listen(integer channel, string name, key id, string message) {
        if (channel == command_channel && llToLower(message) == "toggle debug") {
            if (debug_enabled == TRUE) {
                debug_enabled = FALSE;
                llOwnerSay("Debugging is now disabled.");
            } else {
                debug_enabled = TRUE;
                llOwnerSay("Debugging is now enabled.");
            }
        }
    }
}
