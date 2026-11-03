import os

vam_path = r"C:\Users\SCAD\AndroidStudioProjects\intellifile\app\src\main\java\com\simats\intellifile\ui\voice\VoiceAssistantModal.kt"
with open(vam_path, "r", encoding="utf-8") as f:
    vam_content = f.read()

idx = vam_content.find("fun VoiceAssistantFileCard")
if idx != -1:
    col_idx = vam_content.find("Column(modifier = Modifier.weight(1f))", idx)
    btn_idx = vam_content.find("Spacer(modifier = Modifier.width(8.dp))", col_idx)

    new_col = """Column(modifier = Modifier.weight(1f)) {
            Text(
                text = file.title,
                fontSize = 13.sp,
                fontWeight = FontWeight.Bold,
                color = MaterialTheme.colorScheme.onSurface,
                maxLines = 1
            )
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier.padding(top = 2.dp)
            ) {
                Surface(
                    shape = RoundedCornerShape(4.dp),
                    color = if (file.source == "Device Files") Color(0xFF26E07F).copy(alpha = 0.15f) else MaterialTheme.colorScheme.primary.copy(alpha = 0.15f)
                ) {
                    Text(
                        text = file.source,
                        fontSize = 9.sp,
                        fontWeight = FontWeight.SemiBold,
                        color = if (file.source == "Device Files") Color(0xFF1B8A4E) else MaterialTheme.colorScheme.primary,
                        modifier = Modifier.padding(horizontal = 4.dp, vertical = 1.dp)
                    )
                }
                Spacer(modifier = Modifier.width(6.dp))
                Text(
                    text = "${file.format} • ${file.size}" + (if (file.page != null) " • Page ${file.page}" else ""),
                    fontSize = 11.sp,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        }

        """
    if "\r\n" in vam_content:
        new_col = new_col.replace("\n", "\r\n")

    vam_content = vam_content[:col_idx] + new_col + vam_content[btn_idx:]
    with open(vam_path, "w", encoding="utf-8") as f:
        f.write(vam_content)
    print("Updated VoiceAssistantFileCard column successfully!")
else:
    print("Could not find fun VoiceAssistantFileCard")
