# AI Chat PageComponent Privacy

This documentation does not warrant completeness or correctness. Please report any
missing or wrong information via the
[issue tracker](https://github.com/cce-uzk/AIChatPageComponent/issues).

## Integrated Services

- The plugin employs the following ILIAS services, please consult the respective
  privacy documentation:
    - [Page Editor Service](../../../../../../../Services/COPage/PRIVACY.md): the chat
      is an element of an ILIAS page.
    - [Access Control](../../../../../../../Services/AccessControl/PRIVACY.md): read
      and write permissions of the object containing the page.
    - **Resource Storage Service**: stores background files and files uploaded in
      the chat.

## External Services

The plugin sends data to external services configured by the administrator:

- **AI service** (e.g. KI:connect.nrw, OpenAI): for each message the system prompt,
  the previous messages of the conversation (up to the configured number), the new
  message, background files or retrieved RAG passages, files attached in the
  conversation and, if enabled, the text of the page. No user name, user ID or
  e-mail address is sent.
- **RAG service** (if enabled): background files and files uploaded in the chat
  whose file type is configured for the RAG, and the conversation text used to
  retrieve relevant passages. Files are assigned to collections derived from the
  chat or the chat session; no user name, user ID or e-mail address is sent.

Whether processing by these services is permissible (data processing agreement,
location of processing) has to be assessed by the operating institution.

## Configuration

- **Plugin administration**
    - **AI services and RAG service**: define which external services receive data.
      RAG can be allowed per AI service.
    - **Allow anonymous access**: allows use without login (see below).
    - **Clean up inactive sessions after (days)**: sessions without activity are
      deleted after this period, including messages and attached files.
    - **Max. messages per user/day/chat**: counts the messages of a user per chat
      and day.
- **Chat settings (editors)**
    - **Persistent chat**: keeps the conversation when the user returns.
    - **Include page context**: sends the text of the page to the AI service.
    - **Enable chat file uploads**: allows users to attach files.
    - **Use RAG mode**, **Show sources**, **Allow source downloads**.

## Data being stored

- **Chat session**: for each logged-in user and chat, the **user ID**, the
  **chat ID**, the **creation time** and the **time of last activity**.
- **Messages**: the **text of the user's messages** and the **AI answers**, a
  **timestamp**, the **token usage** of each answer and, in RAG mode, the **sources**
  (file name, pages, excerpt) of each answer.
- **Attachments**: for files uploaded in the chat, the **user ID**, the reference to
  the file in the Resource Storage, the **upload time** and, in RAG mode, the
  identifiers of the file in the RAG service and its processing state there. The
  file itself is stored in the Resource Storage and, in RAG mode, in the RAG service.
- **Pending RAG deletions**: the identifiers of files the RAG service could not
  delete yet, because it was still processing them, until the deletion succeeds.
- **Background files**: stored with the **user ID of the editor** who added them.
- **Browser storage**: the frontend stores the conversation of logged-in users and
  the selected colour theme in the browser's local storage.
- **Anonymous users**: no messages or sessions are stored in the database. The
  conversation exists only in the browser for the current page and is sent to the
  server with each message. File uploads are not available.
- **Logging**: at log level DEBUG, requests to the AI service including message
  texts are written to the ILIAS log. This level should not be used in production.

## Data being presented

- **Users** (read permission) see their own conversation of a chat.
- **Administrators** (plugin configuration, statistics tab) see per chat the number
  of sessions and messages and the time of the last activity. Names or message
  contents are not shown.

## Data being deleted

- A user can clear the conversation of a chat; this deletes the session with all
  messages and attached files.
- Sessions are deleted automatically after the configured period of inactivity,
  including messages and attached files; files are also removed from the RAG
  service. If the RAG service is still processing a file, its deletion is
  repeated later; after about three weeks of failed attempts it is given up and
  logged as an error.
- Administrators can delete all conversations of a chat or the chat completely in
  the statistics tab.
- When a chat element is deleted from a page, its configuration, sessions, messages
  and files are deleted.
- When an ILIAS user account is deleted, the sessions of this user are not deleted
  immediately; they are removed by the automatic cleanup of inactive sessions.
- Data stored in the browser remains there until the user clears the chat or the
  browser storage.

## Data being exported

- The ILIAS export of a page contains the chat configuration and the background
  files. Conversations and user data are not exported.
