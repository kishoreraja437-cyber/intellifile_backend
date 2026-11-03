import os

# =====================================================================
# 1. Update AiScreen.kt
# =====================================================================
ai_screen_path = r"C:\Users\SCAD\AndroidStudioProjects\intellifile\app\src\main\java\com\simats\intellifile\ui\ai\AiScreen.kt"
with open(ai_screen_path, "r", encoding="utf-8") as f:
    ai_content = f.read()

# Add imports if missing
if "import kotlinx.coroutines.Dispatchers" not in ai_content:
    ai_content = ai_content.replace(
        "import kotlinx.coroutines.launch",
        "import kotlinx.coroutines.Dispatchers\nimport kotlinx.coroutines.async\nimport kotlinx.coroutines.launch"
    )

new_ai_block = """                                is AiUserIntent.FileSearch,
                                is AiUserIntent.FileTypeFilter,
                                is AiUserIntent.FileOpen -> {
                                    val searchKeyword = when (intent) {
                                        is AiUserIntent.FileSearch -> intent.query
                                        is AiUserIntent.FileOpen -> intent.targetName
                                        is AiUserIntent.FileTypeFilter -> intent.format
                                        else -> userQuery
                                    }
                                    val formatFilter = when (intent) {
                                        is AiUserIntent.FileSearch -> intent.format
                                        is AiUserIntent.FileTypeFilter -> intent.format
                                        else -> null
                                    }

                                    // 1. Search Local Device Files in parallel (MediaStore + content inspection)
                                    val localDeferred = async(Dispatchers.IO) {
                                        try {
                                            val allLocal = LocalFileManager.getDeviceFiles(context)
                                            val searchTokens = searchKeyword.lowercase()
                                                .split(Regex("[\\\\s_\\\\-,.]+"))
                                                .filter { it.length >= 2 && !setOf("the", "my", "all", "file", "files", "document", "documents", "notes", "for", "with", "about").contains(it) }

                                            allLocal.filter { f ->
                                                val matchesFormat = formatFilter == null || f.format.equals(formatFilter, ignoreCase = true)
                                                if (!matchesFormat) return@filter false

                                                if (intent is AiUserIntent.FileTypeFilter) {
                                                    return@filter true
                                                }

                                                val titleLower = f.title.lowercase()
                                                val pathLower = f.path.lowercase()
                                                val baseName = f.title.substringBeforeLast('.').lowercase()

                                                val directMatch = titleLower.contains(searchKeyword.lowercase()) ||
                                                        baseName.contains(searchKeyword.lowercase()) ||
                                                        pathLower.contains(searchKeyword.lowercase())

                                                if (directMatch) return@filter true

                                                if (searchTokens.isNotEmpty()) {
                                                    val tokenOverlap = searchTokens.count { token ->
                                                        titleLower.contains(token) || pathLower.contains(token)
                                                    }
                                                    if (tokenOverlap == searchTokens.size || (searchTokens.size >= 2 && tokenOverlap >= searchTokens.size - 1)) {
                                                        return@filter true
                                                    }
                                                }

                                                // Local content search for text-based files (< 500KB)
                                                if (f.sizeBytes in 1..500_000L && setOf("TXT", "CSV", "JSON", "MD").contains(f.format.uppercase())) {
                                                    try {
                                                        val textContent = LocalFileManager.readTextContent(context, f.uri.toString(), f.path, f.format, 10000)
                                                        if (textContent.contains(searchKeyword, ignoreCase = true)) {
                                                            return@filter true
                                                        }
                                                        if (searchTokens.isNotEmpty() && searchTokens.all { textContent.contains(it, ignoreCase = true) }) {
                                                            return@filter true
                                                        }
                                                    } catch (_: Exception) {}
                                                }

                                                false
                                            }.map { item ->
                                                AiFileResult(
                                                    id = item.id.toInt(),
                                                    title = item.title,
                                                    format = item.format,
                                                    size = item.sizeFormatted,
                                                    dateText = item.dateText,
                                                    priority = item.priority,
                                                    iconResId = getFormatIcon(item.format),
                                                    source = "Device Files",
                                                    uriString = item.uri.toString(),
                                                    filePath = item.path,
                                                    category = null
                                                )
                                            }
                                        } catch (e: Exception) {
                                            emptyList<AiFileResult>()
                                        }
                                    }

                                    // 2. Search AI Vault via Backend API in parallel
                                    val vaultDeferred = async(Dispatchers.IO) {
                                        aiRepository.sendChatMessage(
                                            message = userQuery,
                                            conversationId = currentConversationId
                                        )
                                    }

                                    val localMatches = localDeferred.await()
                                    val vaultResult = vaultDeferred.await()

                                    isThinking = false
                                    chatMessages.remove(loadingMsg)

                                    val vaultMatches = mutableListOf<AiFileResult>()
                                    var backendExplanation: String? = null

                                    vaultResult.onSuccess { response ->
                                        currentConversationId = response.conversation_id
                                        backendExplanation = response.message?.text ?: response.reply
                                        val rawFiles = response.message?.files
                                            ?: response.message?.related_files
                                            ?: response.related_files
                                            ?: emptyList()

                                        val mapped = rawFiles.map { rf ->
                                            val prio = when (rf.priority?.uppercase()) {
                                                "IMPORTANT" -> PriorityLevel.IMPORTANT
                                                "LOW" -> PriorityLevel.LOW
                                                "NORMAL" -> PriorityLevel.NORMAL
                                                else -> null
                                            }
                                            val icon = getFormatIcon(rf.format)
                                            AiFileResult(
                                                id = rf.id,
                                                title = rf.title,
                                                format = rf.format ?: "PDF",
                                                size = rf.size ?: "1.0 MB",
                                                dateText = rf.dateText ?: "Recent",
                                                priority = prio,
                                                iconResId = icon,
                                                page = rf.page,
                                                snippet = rf.snippet,
                                                source = rf.source ?: "AI Vault",
                                                uriString = rf.uri_string,
                                                filePath = rf.file_path,
                                                fileUrl = rf.file_url,
                                                category = rf.category
                                            )
                                        }
                                        vaultMatches.addAll(mapped)
                                    }.onFailure { err ->
                                        if (localMatches.isEmpty()) {
                                            chatMessages.add(
                                                ChatMessage.Ai(
                                                    text = "Could not reach the AI service: ${err.localizedMessage}\\nPlease make sure the backend is running."
                                                )
                                            )
                                            return@launch
                                        }
                                    }

                                    // 3. Merge & Deduplicate (Device Files + AI Vault Files)
                                    val allMatches = mutableListOf<AiFileResult>()
                                    allMatches.addAll(localMatches)
                                    for (vf in vaultMatches) {
                                        if (allMatches.none { it.title.equals(vf.title, ignoreCase = true) }) {
                                            allMatches.add(vf)
                                        }
                                    }

                                    // 4. Formulate Response & Display in Chat UI
                                    if (allMatches.isEmpty()) {
                                        val emptyMsg = "No matching files found for '$searchKeyword'. Try another filename or keyword."
                                        chatMessages.add(ChatMessage.Ai(text = emptyMsg, files = emptyList()))
                                    } else {
                                        val reply = when (intent) {
                                            is AiUserIntent.FileOpen -> {
                                                if (allMatches.size == 1) {
                                                    val singleDoc = allMatches.first()
                                                    onOpenFile(singleDoc)
                                                    "Opening '**${singleDoc.title}**'. Tap below to view if not automatically opened."
                                                } else {
                                                    "I found **${allMatches.size} files** matching '**$searchKeyword**'. Tap a file card below to open it:"
                                                }
                                            }
                                            is AiUserIntent.FileTypeFilter -> {
                                                val fmt = formatFilter ?: "matching"
                                                "Here are your **${allMatches.size} ${fmt} file${if (allMatches.size > 1) "s" else ""}** found across Device Files and AI Vault:"
                                            }
                                            else -> {
                                                if (vaultMatches.isNotEmpty() && !backendExplanation.isNullOrBlank() && !backendExplanation!!.contains("could not find information", ignoreCase = true) && !backendExplanation!!.contains("No matching files", ignoreCase = true)) {
                                                    "I found **${allMatches.size} matching file${if (allMatches.size > 1) "s" else ""}** for '**$searchKeyword**' across Device Files and AI Vault:\\n\\n${backendExplanation}"
                                                } else {
                                                    "I found **${allMatches.size} matching file${if (allMatches.size > 1) "s" else ""}** for '**$searchKeyword**' across Device Files and AI Vault:"
                                                }
                                            }
                                        }

                                        chatMessages.add(
                                            ChatMessage.Ai(
                                                text = reply,
                                                files = allMatches
                                            )
                                        )
                                    }
                                }"""

start_marker = "is AiUserIntent.FileSearch,\n                                is AiUserIntent.FileTypeFilter,\n                                is AiUserIntent.FileOpen -> {"
end_marker = "chatMessages.add(\n                                            ChatMessage.Ai(\n                                                text = reply,\n                                                files = allMatches\n                                            )\n                                        )\n                                    }\n                                }"

if start_marker not in ai_content:
    start_marker = start_marker.replace("\n", "\r\n")
    end_marker = end_marker.replace("\n", "\r\n")
    new_ai_block = new_ai_block.replace("\n", "\r\n")

start_pos = ai_content.find(start_marker)
end_pos = ai_content.find(end_marker) + len(end_marker)

if start_pos != -1 and end_pos != -1:
    ai_content = ai_content[:start_pos] + new_ai_block + ai_content[end_pos:]
    with open(ai_screen_path, "w", encoding="utf-8") as f:
        f.write(ai_content)
    print("Updated AiScreen.kt successfully!")
else:
    print("Could not find start/end markers in AiScreen.kt:", start_pos, end_pos)

# =====================================================================
# 2. Update VoiceAssistantModal.kt
# =====================================================================
vam_path = r"C:\Users\SCAD\AndroidStudioProjects\intellifile\app\src\main\java\com\simats\intellifile\ui\voice\VoiceAssistantModal.kt"
with open(vam_path, "r", encoding="utf-8") as f:
    vam_content = f.read()

# Add imports
if "import kotlinx.coroutines.Dispatchers" not in vam_content:
    vam_content = vam_content.replace(
        "import kotlinx.coroutines.launch",
        "import kotlinx.coroutines.Dispatchers\nimport kotlinx.coroutines.async\nimport kotlinx.coroutines.launch"
    )

new_vam_search_block = """                    // -------------------------------------------------------------
                    // 3. FILE_SEARCH_COMMAND
                    // -------------------------------------------------------------
                    is VoiceIntentResult.FileSearch -> {
                        val term = intentResult.query.lowercase()
                        val fmt = intentResult.formatFilter

                        // 1. Search local device files in parallel
                        val localDeferred = async(Dispatchers.IO) {
                            try {
                                val allFiles = LocalFileManager.getDeviceFiles(context)
                                val searchTokens = term.split(Regex("[\\\\s_\\\\-,.]+"))
                                    .filter { it.length >= 2 && !setOf("the", "my", "all", "file", "files", "document", "documents", "notes", "for", "with", "about").contains(it) }

                                allFiles.filter { file ->
                                    val matchFormat = fmt == null || file.format.equals(fmt, ignoreCase = true)
                                    if (!matchFormat) return@filter false
                                    if (term.isBlank()) return@filter true

                                    val titleLower = file.title.lowercase()
                                    val pathLower = file.path.lowercase()
                                    val baseName = file.title.substringBeforeLast('.').lowercase()

                                    if (titleLower.contains(term) || baseName.contains(term) || pathLower.contains(term)) {
                                        return@filter true
                                    }

                                    if (searchTokens.isNotEmpty()) {
                                        val overlap = searchTokens.count { titleLower.contains(it) || pathLower.contains(it) }
                                        if (overlap == searchTokens.size || (searchTokens.size >= 2 && overlap >= searchTokens.size - 1)) {
                                            return@filter true
                                        }
                                    }

                                    if (file.sizeBytes in 1..500_000L && setOf("TXT", "CSV", "JSON", "MD").contains(file.format.uppercase())) {
                                        try {
                                            val content = LocalFileManager.readTextContent(context, file.uri.toString(), file.path, file.format, 10000)
                                            if (content.contains(term, ignoreCase = true)) return@filter true
                                            if (searchTokens.isNotEmpty() && searchTokens.all { content.contains(it, ignoreCase = true) }) return@filter true
                                        } catch (_: Exception) {}
                                    }

                                    false
                                }
                            } catch (_: Exception) {
                                emptyList<LocalFileItem>()
                            }
                        }

                        // 2. Search AI Vault via backend API in parallel
                        val vaultDeferred = async(Dispatchers.IO) {
                            try {
                                aiRepository.sendChatMessage(
                                    message = query,
                                    conversationId = currentConversationId
                                )
                            } catch (_: Exception) {
                                null
                            }
                        }

                        val filteredLocal = localDeferred.await()
                        val vaultResult = vaultDeferred.await()

                        val vaultFiles = mutableListOf<AiFileResult>()
                        vaultResult?.onSuccess { response ->
                            currentConversationId = response.conversation_id
                            val rawFiles = response.message?.files
                                ?: response.message?.related_files
                                ?: response.related_files
                                ?: emptyList()

                            val mapped = rawFiles.map { rf ->
                                val prio = when (rf.priority?.uppercase()) {
                                    "IMPORTANT" -> PriorityLevel.IMPORTANT
                                    "LOW" -> PriorityLevel.LOW
                                    "NORMAL" -> PriorityLevel.NORMAL
                                    else -> null
                                }
                                val icon = when (rf.format?.uppercase()) {
                                    "DOCX", "DOC" -> R.drawable.doc
                                    "PPTX", "PPT" -> R.drawable.ppt
                                    else -> R.drawable.pdf
                                }
                                AiFileResult(
                                    id = rf.id,
                                    title = rf.title,
                                    format = rf.format ?: "PDF",
                                    size = rf.size ?: "1.0 MB",
                                    dateText = rf.dateText ?: "Recent",
                                    priority = prio,
                                    iconResId = icon,
                                    page = rf.page,
                                    snippet = rf.snippet,
                                    source = rf.source ?: "AI Vault",
                                    uriString = rf.uri_string,
                                    filePath = rf.file_path,
                                    fileUrl = rf.file_url,
                                    category = rf.category
                                )
                            }
                            vaultFiles.addAll(mapped)
                        }

                        val dedupedVaultFiles = vaultFiles.filter { vf ->
                            filteredLocal.none { it.title.equals(vf.title, ignoreCase = true) }
                        }

                        foundDeviceFiles = filteredLocal
                        attachedAiFiles = dedupedVaultFiles
                        val totalCount = filteredLocal.size + dedupedVaultFiles.size

                        if (totalCount > 0) {
                            val reply = if (fmt != null && term.isNotBlank()) {
                                "Found $totalCount $fmt file(s) matching '$term' across Device Files and AI Vault."
                            } else if (fmt != null) {
                                "Found $totalCount $fmt file(s) across Device Files and AI Vault."
                            } else {
                                "I found $totalCount file(s) matching '$term' across Device Files and AI Vault."
                            }
                            assistantReplyText = reply
                            statusMessage = "Search results"
                            voiceState = AssistantVoiceState.SPEAKING
                            speakAnswer(reply)

                            if (term.isNotBlank()) {
                                onSearchHome(term)
                            }
                        } else {
                            val noMatch = "No matching files found for '${intentResult.query}'. Try another filename or keyword."
                            assistantReplyText = noMatch
                            statusMessage = "No files found"
                            voiceState = AssistantVoiceState.IDLE
                            speakAnswer(noMatch)
                        }
                    }"""

vam_start_marker = "                    // -------------------------------------------------------------\n                    // 3. FILE_SEARCH_COMMAND\n                    // -------------------------------------------------------------\n                    is VoiceIntentResult.FileSearch -> {"
vam_end_marker = "                            speakAnswer(noMatch)\n                        }\n                    }"

if vam_start_marker not in vam_content:
    vam_start_marker = vam_start_marker.replace("\n", "\r\n")
    vam_end_marker = vam_end_marker.replace("\n", "\r\n")
    new_vam_search_block = new_vam_search_block.replace("\n", "\r\n")

v_start_pos = vam_content.find(vam_start_marker)
v_end_pos = vam_content.find(vam_end_marker) + len(vam_end_marker)

if v_start_pos != -1 and v_end_pos != -1:
    vam_content = vam_content[:v_start_pos] + new_vam_search_block + vam_content[v_end_pos:]
    print("Updated VoiceAssistantModal search block successfully!")
else:
    print("Could not find search markers in VoiceAssistantModal.kt:", v_start_pos, v_end_pos)

# Also update attachedAiFiles click in VoiceAssistantModal.kt to resolve uriString/path
old_click_block = """                                    val localMatch = LocalFileManager.getDeviceFiles(context).firstOrNull {
                                        it.title.equals(file.title, ignoreCase = true) ||
                                                it.title.equals(file.title.substringBeforeLast('.'), ignoreCase = true)
                                    }
                                    onDismiss()
                                    onOpenFile(
                                        FileDetailData(
                                            fileName = file.title,
                                            fileSize = file.size,
                                            fileType = file.format,
                                            createdDate = file.dateText,
                                            modifiedDate = file.dateText,
                                            priority = file.priority,
                                            iconResId = file.iconResId,
                                            uriString = localMatch?.uri?.toString() ?: "",
                                            path = localMatch?.path ?: ""
                                        )
                                    )"""

new_click_block = """                                    val localMatch = LocalFileManager.getDeviceFiles(context).firstOrNull {
                                        it.title.equals(file.title, ignoreCase = true) ||
                                                it.title.equals(file.title.substringBeforeLast('.'), ignoreCase = true) ||
                                                (!file.uriString.isNullOrBlank() && it.uri.toString() == file.uriString) ||
                                                (!file.filePath.isNullOrBlank() && it.path == file.filePath)
                                    }
                                    val finalUri = if (!file.uriString.isNullOrBlank()) file.uriString else (localMatch?.uri?.toString() ?: "")
                                    val finalPath = if (!file.filePath.isNullOrBlank()) file.filePath else (localMatch?.path ?: "")

                                    onDismiss()
                                    onOpenFile(
                                        FileDetailData(
                                            fileName = file.title,
                                            fileSize = file.size,
                                            fileType = "${file.format} Document",
                                            createdDate = file.dateText,
                                            modifiedDate = file.dateText,
                                            priority = file.priority,
                                            iconResId = file.iconResId,
                                            uriString = finalUri,
                                            path = finalPath
                                        )
                                    )"""

if old_click_block not in vam_content:
    old_click_block = old_click_block.replace("\n", "\r\n")
    new_click_block = new_click_block.replace("\n", "\r\n")

if old_click_block in vam_content:
    vam_content = vam_content.replace(old_click_block, new_click_block)
    print("Updated attachedAiFiles click in VoiceAssistantModal.kt!")
else:
    print("Could not find old_click_block in VoiceAssistantModal.kt")

# Also update section title to "AI Vault Files"
vam_content = vam_content.replace(
    'text = "Referenced Documents (${attachedAiFiles.size})"',
    'text = "AI Vault Files (${attachedAiFiles.size})"'
)

with open(vam_path, "w", encoding="utf-8") as f:
    f.write(vam_content)

print("VoiceAssistantModal.kt saved successfully!")

