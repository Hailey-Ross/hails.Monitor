# Database Version
*This version of the script is provided as is.*

---

## You will need:
- Webserver  
- Database  
- Basic database knowledge  

## Do NOT:
- Request help  
- Host your `config.php` in a non-secure directory  
- Share your API key  

---

### General Setup
**Note:**  
*The files will follow a similar layout to the repository you are viewing.*  
*Everything inside of `PHP` will be the root of your webserver.*  

1. **Download or Copy the Files:**
   - Download or copy/paste the contents of the `PHP` folder to your computer for editing.
   - Copy and Paste the `LSL` scripts into your inventory in Second Life.

2. **Update the Server URL:**
   - Update the scripts to point to your webserver, where you are hosting the PHP file `av.php`.  
   Example: `string server_url = "https://mysite.com/av.php";`  
   Files to edit: `hails.Lookup.lsl`, `hails.Monitor.lsl`

3. **Create an API Key:**
   - Next, create or generate an API key that must match in all locations and/or scripts.  
   Example: `string API_KEY = "SECRET-API-KEY";`  
   Files to edit: `config.php`, `hails.Lookup.lsl`, `hails.Monitor.lsl`

4. **Place the .htaccess File:**
   - Do NOT forget to place the `.htaccess` file in the same directory as your `config.php` file.  
   - There is an **optional** `.htaccess` file included to remove directory listing for `av.php` if no index file is present in the same folder.

5. **Run SQL File**
   - Connect to your Database using your favorite SQL Client (HeidiSQL, DBeaver, Oracel, Workbench..)
   - Copy/Paste the `run_me.sql` file from the `SQL` Directory to create your table.

6. **Run the LSL Scripts:**
   - Place `hails.Monitor.lsl` and `hails.Lookup.lsl` in an object on your land with the edits from above.
   - Say `hails info` into public chat for Commands.

7. **Nothing is Happening?!**
   - Say `/2 toggle debug` in public chat and quickly resend the command after 5-10 seconds.
   - Read the output.
   - [StackOverflow](https://stackoverflow.com/)
