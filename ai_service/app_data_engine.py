from typing import Dict, Any, List

class AppDataEngine:
    """
    Answers IntelliFile application-specific and repository queries using
    real authenticated database statistics and live user file records.
    Never invents or mocks numbers.
    """

    @staticmethod
    def format_response(query: str, sub_intent: str, app_stats: Dict[str, Any], detected_formats: List[str]) -> Dict[str, Any]:
        total_files = int(app_stats.get("total_files", 0))
        format_counts = app_stats.get("format_counts", {})
        priority_counts = app_stats.get("priority_counts", {})
        important_count = int(priority_counts.get("IMPORTANT", 0))
        normal_count = int(priority_counts.get("NORMAL", 0))
        low_count = int(priority_counts.get("LOW", 0))
        bookmarked_count = int(app_stats.get("bookmarked_count", 0))
        saved_count = int(app_stats.get("saved_count", 0))
        today_count = int(app_stats.get("today_count", 0))
        most_used_format = app_stats.get("most_used_format", "None")
        recent_files = app_stats.get("recent_files", [])

        lower_q = query.lower()
        files_to_attach: List[Dict[str, Any]] = []

        # 1. Uploaded Today
        if sub_intent == "TODAY_STATS" or any(k in lower_q for k in ["uploaded today", "files today", "today's uploads", "today"]):
            if today_count == 0:
                answer = f"You haven't uploaded any new files today. You currently have a total of **{total_files} stored files** in your repository."
            else:
                answer = f"You have uploaded **{today_count} file{'s' if today_count != 1 else ''}** today (out of {total_files} total files)."

        # 2. Priority Statistics (Important files)
        elif sub_intent == "PRIORITY_STATS" or any(k in lower_q for k in ["important", "priority"]):
            answer = (
                f"You have **{important_count} Important file{'s' if important_count != 1 else ''}** saved in your workspace.\n\n"
                f"• **Important**: {important_count}\n"
                f"• **Normal**: {normal_count}\n"
                f"• **Low**: {low_count}\n\n"
                f"Total files: {total_files}."
            )

        # 3. Format Statistics & Breakdown
        elif sub_intent == "FORMAT_STATS" or any(k in lower_q for k in ["pdf", "docx", "ppt", "format", "file type", "most used"]):
            if any(k in lower_q for k in ["most used", "which file type do i use most", "which format"]):
                max_fmt_count = format_counts.get(most_used_format, 0)
                answer = (
                    f"Your most frequently used file format is **{most_used_format}**, with **{max_fmt_count} file{'s' if max_fmt_count != 1 else ''}** "
                    f"out of your {total_files} total files."
                )
            else:
                lines = ["Here is the breakdown of your files by format:"]
                for target_fmt in (detected_formats if detected_formats else ["PDF", "DOCX", "PPT", "TXT", "CSV"]):
                    count = format_counts.get(target_fmt, 0)
                    if target_fmt in ["PPT", "PPTX"]:
                        count = format_counts.get("PPT", 0) + format_counts.get("PPTX", 0)
                    lines.append(f"• **{target_fmt}**: {count} file{'s' if count != 1 else ''}")

                if most_used_format and most_used_format != "None":
                    lines.append(f"\nMost used format: **{most_used_format}** ({format_counts.get(most_used_format, 0)} files).")
                answer = "\n".join(lines)

        # 4. Bookmarks
        elif sub_intent == "BOOKMARK_STATS" or "bookmark" in lower_q:
            answer = f"You currently have **{bookmarked_count} bookmarked file{'s' if bookmarked_count != 1 else ''}** in your IntelliFile workspace."

        # 5. Saved Files
        elif sub_intent == "SAVED_STATS" or "saved" in lower_q:
            answer = f"You currently have **{saved_count} saved file{'s' if saved_count != 1 else ''}** in your collection."

        # 6. Recent Files
        elif sub_intent == "RECENT_FILES" or any(k in lower_q for k in ["recent", "recently uploaded", "show my files", "list my files"]):
            if not recent_files:
                answer = f"You currently have {total_files} files stored, but no recent upload history was found."
            else:
                answer = f"Here are your **{len(recent_files)} most recently uploaded file{'s' if len(recent_files) != 1 else ''}** in IntelliFile:"
                files_to_attach = recent_files

        # 7. Total Files / Document Count (default)
        else:
            if total_files == 0:
                answer = "You currently have **0 files** stored in your IntelliFile repository. You can upload documents anytime from My Files or AI Vault."
            else:
                answer = (
                    f"You currently have a total of **{total_files} file{'s' if total_files != 1 else ''}** stored in your IntelliFile workspace."
                )
                if format_counts:
                    fmt_summary = ", ".join(f"{cnt} {fmt}" for fmt, cnt in format_counts.items())
                    answer += f"\n\n**Format Breakdown**: {fmt_summary}."

        return {
            "answer": answer,
            "files": files_to_attach,
            "category": "SEARCH" if files_to_attach else "ANALYSIS"
        }
