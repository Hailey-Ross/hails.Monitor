# Database Version
*This version of the script is provided as is.*

---

## You will need:
- Webserver
   - [Server Hosting](https://letmegooglethat.com/?q=webhosting)  
- Database  
- Basic database knowledge
- SQL Client
   - [HeidiSQL](https://www.heidisql.com/)  
   - [Others](https://alternativeto.net/software/heidisql/)  

## You will want:  
- [Grafana](https://grafana.net) Account
   - Free tier works well.

## Do NOT:
- [Request help](https://stackoverflow.com/)  
- Host your `config.php` in [a non-secure directory](https://letmegooglethat.com/?q=How+to+securely+host+a+config+file) 
- [Share/Post your API key in public spaces](https://letmegooglethat.com/?q=API+Security)  

---

### Example Setup
**Note:**  
*The files will follow a similar layout to the repository you are viewing.*  
*Everything inside of `PHP` will be the root of your webserver.*  

1. **Download or Copy the Files:**
   - Download or copy/paste the contents of the `PHP` folder to your computer for editing.
   - Copy and paste the `LSL` scripts into your inventory in Second Life.

2. **Update the Server URL:**
   - Update the scripts to point to your webserver, where you are hosting the PHP file `av.php`.  
   Example: `string server_url = "https://mysite.com/av.php";`  
   Files to edit: `hails.Lookup.lsl`, `hails.Monitor.lsl`, `hails.CronServer.lsl`  

3. **Create an API Key:**
   - Next, create or generate an API key that must match in all locations and/or scripts.  
   Example: `string API_KEY = "SECRET-API-KEY";`  
   Files to edit: `config.php`, `hails.Lookup.lsl`, `hails.Monitor.lsl`, `hails.CronServer.lsl`

4. **Upload to your Webserver**
   - Connect to your webserver using a FTP/SFTP Client
   - Upload the contents of the `PHP` directory you edited to your webserver.

5. **Run the SQL File:**
   - Connect to your database using your favorite SQL client (e.g., HeidiSQL, DBeaver, Oracle, MySQL Workbench).
   - Copy and paste the `run_me.sql` file from the `SQL` directory.
   - Run the file as a query to create your tables.

6. **Check for the .htaccess Files:**
   - Do NOT forget to include the `.htaccess` file in the same directory as your `config.php` file.  
   - There is an **optional** `.htaccess` file included with `av.php` if no index file is present in the same folder.
   - Most setups will want these files or a variation in place.

7. **Run the LSL Scripts:**  
   - Place `hails.Monitor.lsl` and `hails.Lookup.lsl` in an object on your land with the edits from above.  
      - `hails.Lookup.lsl` can optionally be used seperately as standalone lookup script from the scanner.  
   - Place `hails.CronServer.lsl` into an additional object.  
      - You can toggle the debug on chat channel 3 for the this script. Ex. `/3 toggle debug`  
   - Say `hails info` in public chat to view available commands for the applicable scripts.

8. **Nothing is Happening?!**
   - Say `/2 toggle debug` in public chat and quickly resend the command after 5-10 seconds.
   - Read the output.
   - [StackOverflow](https://stackoverflow.com/) & [LSL Wiki](https://wiki.secondlife.com/wiki/LSL_Portal).  

9. **Works? Lookup credential security practices**
   - [StackOverflow](https://stackoverflow.com/)
   - [Google](https://google.com/)
   - Most likely, change your API-Key
