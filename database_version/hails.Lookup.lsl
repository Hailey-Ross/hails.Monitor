// Script Created by Hailey Enfield
// Queries the server for avatar info by UUID

string server_url = "https://YOUR-SERVER-HERE.com/av.php"; // URL to your PHP script that handles avatar queries
string API_KEY = "YOUR-API-KEY"; // Define your API key here


integer waiting_for_response = FALSE; // Prevent multiple simultaneous requests

// Function to request avatar info from the server
requestAvatarInfo(string avatar_key) {
    if (!waiting_for_response) {
        waiting_for_response = TRUE; // Prevent further requests until this one is complete
        string post_data = "api_key=" + API_KEY + "&action=query" + "&avatar_key=" + avatar_key;
        llHTTPRequest(server_url, [HTTP_METHOD, "POST", HTTP_MIMETYPE, "application/x-www-form-urlencoded"], post_data);
    }
}

default {
    state_entry() {
        // Listen on public channel (0) to catch all chat inputs
        llListen(0, "", llGetOwner(), "");
    }

    listen(integer channel, string name, key id, string message) {
        // Ensure we're only processing the owner’s messages
        if (id == llGetOwner()) {
            // Check if the command starts with 'get avatar '
            if (llGetSubString(message, 0, 10) == "get avatar ") {
                // Extract the avatar key from the message
                string avatar_key = llGetSubString(message, 11, -1);
                requestAvatarInfo(avatar_key);
            }
        }
    }

    http_response(key request_id, integer status, list metadata, string body) {
        if (waiting_for_response) {
            if (status == 200) {
                // Parse the JSON response
                list response_data = llJson2List(body);

                // Initialize variables
                string avatar_name = "";
                string region_name = "";
                string first_seen = "";
                string last_seen = "";

                // Manually map the values based on expected keys
                if (llListFindList(response_data, ["name"]) != -1) {
                    avatar_name = llList2String(response_data, llListFindList(response_data, ["name"]) + 1);
                }
                if (llListFindList(response_data, ["region"]) != -1) {
                    region_name = llList2String(response_data, llListFindList(response_data, ["region"]) + 1);
                }
                if (llListFindList(response_data, ["first_seen"]) != -1) {
                    first_seen = llList2String(response_data, llListFindList(response_data, ["first_seen"]) + 1);
                }
                if (llListFindList(response_data, ["last_seen"]) != -1) {
                    last_seen = llList2String(response_data, llListFindList(response_data, ["last_seen"]) + 1);
                }

                // Format the response output
                string output = "Visitor Info:\n" +
                                "Name: " + avatar_name + "\n" +
                                "Region: " + region_name + "\n" +
                                "First Seen: " + first_seen + "\n" +
                                "Last Seen: " + last_seen;

                llOwnerSay(output);  // Display the formatted output
            } else {
                llOwnerSay("Error fetching avatar data from the server.");
            }
            waiting_for_response = FALSE; // Allow future requests after receiving a response
        }
    }
}
