// Script by Hailey Enfield
// If an entry has an empty avatar_name it will update the database with the correct name.

list allowed_users = ["00000000-0000-0000-0000-000000000000", "00000000-0000-0000-0000-000000000000"];
string server_url = "https://YOUR-URL-HERE.com/av.php"; 
string API_KEY = "YOUR-API-HERE"; 
integer update_interval = 7200; 
integer debug_enabled = FALSE;
integer command_channel = 2;
list pending_keys;
string current_avatar_key;
integer expecting_keys = TRUE; 
integer delay_update_interval = 5; 

// Function to handle debug messages
debug(string message) {
    if (debug_enabled) {
        llOwnerSay(message);
    }
}

// Function to perform the main check
performCheck() {
    string post_data = "api_key=" + API_KEY + "&action=check_empty_names";
    debug("Requesting empty avatar names...");
    expecting_keys = TRUE; // Set flag to expect a list of keys
    llHTTPRequest(server_url, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"], post_data);
}

default {
    state_entry() {
        llSetTimerEvent(update_interval);
        llListen(command_channel, "", llGetOwner(), ""); // Listen on command channel
        llOwnerSay("Periodic Avatar Name Updater is active.");
        performCheck(); // Run once immediately upon startup
    }

    timer() {
        if (llGetListLength(pending_keys) > 0) {
            current_avatar_key = llList2String(pending_keys, 0);
            pending_keys = llDeleteSubList(pending_keys, 0, 0); // Remove processed key from list
            debug("Looking up avatar name for UUID: " + current_avatar_key);
            llRequestAgentData(current_avatar_key, DATA_NAME); // Lookup avatar name

            llSetTimerEvent(delay_update_interval); // Set delay between each avatar name update
        } else {
            llSetTimerEvent(update_interval); // Reset main check interval
            debug("All keys processed; setting timer for next check.");
        }
    }

    http_response(key request_id, integer status, list metadata, string body) {
        debug("HTTP Response Status: " + (string)status);

        if (status == 200) {
            if (expecting_keys == TRUE) { // Only parse JSON if expecting keys
                integer start_index = llSubStringIndex(body, "[") + 1;
                integer end_index = llSubStringIndex(body, "]");
                
                if (start_index >= 1 && end_index > start_index) {
                    string clean_body = llDeleteSubString(body, 0, start_index);
                    clean_body = llDeleteSubString(clean_body, end_index - start_index, -1);
                    
                    pending_keys = llParseString2List(clean_body, ["\"", ","], []); 
                    debug("Parsed keys to process: " + llDumpList2String(pending_keys, ", "));
                    
                    if (llGetListLength(pending_keys) > 0) {
                        llSetTimerEvent(delay_update_interval); // Start processing with delay
                    } else {
                        debug("No entries with empty names found. Sleeping for next interval.");
                        llSetTimerEvent(update_interval); // No entries; reschedule next check
                    }
                } else {
                    debug("Error parsing JSON response: invalid indices.");
                }
                expecting_keys = FALSE; // Reset flag after processing
            } else {
                debug("Name update acknowledged by server.");
            }
        } else {
            debug("Error fetching data from server. Status: " + (string)status);
        }
    }

    dataserver(key query_id, string avatar_name) {
        if (llStringLength(avatar_name) > 0) {
            // Use the stored current_avatar_key to ensure we send the correct UUID
            string post_data = "api_key=" + API_KEY + "&action=update_avatar_name&avatar_key=" + current_avatar_key + "&avatar_name=" + llEscapeURL(avatar_name);
            debug("Sending to server: " + post_data);  // Log data being sent
            llHTTPRequest(server_url, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"], post_data);
        } else {
            debug("Avatar name lookup failed for UUID: " + current_avatar_key);
        }
    }

    listen(integer channel, string name, key id, string message) {
        if (channel == command_channel) {
            if (llToLower(message) == "toggle debug") {
                debug_enabled = !debug_enabled; // Toggle debug
                if (debug_enabled) {
                    llOwnerSay("Debugging is now enabled.");
                } else {
                    llOwnerSay("Debugging is now disabled.");
                }
            }
        }
    }
}
