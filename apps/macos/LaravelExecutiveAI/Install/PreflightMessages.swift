// Blocker texts, verbatim from docs/architecture/support-matrix.md §6 (EN and SV). Only blockers
// have user-facing text; warnings go to the log and the Done-screen checklist.

import Foundation

enum PreflightMessages {
    static func macOSTooOld(version: String) -> PreflightMessage {
        PreflightMessage(
            headlineEN: "This Mac is running macOS \(version). Laravel Executive AI needs macOS 15 or later.",
            actionEN: "Update macOS in System Settings › General › Software Update, then run the installer again.",
            headlineSV: "Den här datorn kör macOS \(version). Laravel Executive AI kräver macOS 15 eller senare.",
            actionSV: "Uppdatera macOS i Systeminställningar › Allmänt › Programuppdatering och kör sedan installeraren igen."
        )
    }

    static let intel = PreflightMessage(
        headlineEN: "This Mac has an Intel processor. Laravel Executive AI runs only on Macs with Apple Silicon (M1 or later).",
        actionEN: "Intel Macs are not supported and no Intel version is planned.",
        headlineSV: "Den här datorn har en Intel-processor. Laravel Executive AI fungerar bara på Mac-datorer med Apple Silicon (M1 eller senare).",
        actionSV: "Intel-datorer stöds inte och ingen Intel-version är planerad."
    )

    static func tooLittleMemory(bytes: UInt64) -> PreflightMessage {
        let ram = format(bytes: Int64(clamping: bytes))

        return PreflightMessage(
            headlineEN: "This Mac has \(ram) of memory. Laravel Executive AI needs at least 8 GB.",
            actionEN: "",
            headlineSV: "Den här datorn har \(ram) minne. Laravel Executive AI kräver minst 8 GB.",
            actionSV: ""
        )
    }

    static func notEnoughDisk(required: Int64, available: Int64) -> PreflightMessage {
        let requiredText = format(bytes: required)
        let availableText = format(bytes: available)

        return PreflightMessage(
            headlineEN: "Not enough free space. The install needs \(requiredText) free on this disk and \(availableText) is available.",
            actionEN: "Free up space (Apple menu › About This Mac › Storage) and run the installer again.",
            headlineSV: "För lite ledigt utrymme. Installationen behöver \(requiredText) ledigt på den här disken och \(availableText) är tillgängligt.",
            actionSV: "Frigör utrymme (Apple-menyn › Om den här datorn › Lagring) och kör installeraren igen."
        )
    }

    static let cannotWriteLibrary = PreflightMessage(
        headlineEN: "Laravel Executive AI can't write to this user's Library folder.",
        actionEN: "Log in with a standard user account that has a permanent home folder (not Guest), and make sure the disk is not read-only.",
        headlineSV: "Laravel Executive AI kan inte skriva till den här användarens Bibliotek-mapp.",
        actionSV: "Logga in med ett vanligt användarkonto som har en permanent hemmapp (inte Gäst) och kontrollera att disken inte är skrivskyddad."
    )

    static func allPortsTaken(first: UInt16, last: UInt16) -> PreflightMessage {
        PreflightMessage(
            headlineEN: "Another program is using every network port Laravel Executive AI can run on (127.0.0.1:\(first)–\(last)).",
            actionEN: "Quit the program using those ports and run the installer again.",
            headlineSV: "Ett annat program använder alla nätverksportar som Laravel Executive AI kan köra på (127.0.0.1:\(first)–\(last)).",
            actionSV: "Avsluta programmet som använder portarna och kör installeraren igen."
        )
    }

    static func newerVersionInstalled(installed: String, this: String) -> PreflightMessage {
        PreflightMessage(
            headlineEN: "A newer version of Laravel Executive AI (\(installed)) is already installed. This installer is version \(this).",
            actionEN: "Download the latest installer, or open the installed app and let it update itself.",
            headlineSV: "En nyare version av Laravel Executive AI (\(installed)) är redan installerad. Den här installeraren är version \(this).",
            actionSV: "Hämta den senaste installeraren, eller öppna den installerade appen och låt den uppdatera sig själv."
        )
    }

    static let notCorrectlySigned = PreflightMessage(
        headlineEN: "This copy of Laravel Executive AI is not correctly signed and may have been modified.",
        actionEN: "Delete it, download the installer again from the official release page, and verify the download before opening it.",
        headlineSV: "Den här kopian av Laravel Executive AI är inte korrekt signerad och kan ha ändrats.",
        actionSV: "Radera den, hämta installeraren igen från den officiella releasesidan och verifiera nedladdningen innan du öppnar den."
    )

    /// Decimal GB/MB with at most one decimal, the way About This Mac shows storage.
    static func format(bytes: Int64) -> String {
        let formatter = ByteCountFormatter()
        formatter.countStyle = .decimal
        formatter.allowedUnits = [.useGB, .useMB]
        formatter.zeroPadsFractionDigits = false

        return formatter.string(fromByteCount: bytes)
    }
}
