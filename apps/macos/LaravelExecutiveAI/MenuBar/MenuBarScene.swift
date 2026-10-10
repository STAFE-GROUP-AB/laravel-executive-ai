// The MenuBarExtra scene and the Settings scene (S1-09, blueprint §1.1).
//
// `.window` style so the panel is a real SwiftUI view (lists, disclosures, buttons), not a menu.
// The label is the state icon plus the approval count — the count is the only badge
// (health contract §7). Settings is the standard `Settings` scene, so the app menu's "Settings…"
// (⌘,) and the panel's "Settings" button open the same window: the §1.1 sections still to come,
// and Uninstall (S1-13).

import SwiftUI

struct MenuBarScene: Scene {
    @Bindable var model: PanelModel

    var body: some Scene {
        MenuBarExtra {
            MenuBarPanelView(model: model)
        } label: {
            MenuBarLabel(model: model)
        }
        .menuBarExtraStyle(.window)

        Settings {
            SettingsView(model: model)
        }
    }
}

struct MenuBarLabel: View {
    let model: PanelModel

    var body: some View {
        HStack(spacing: 2) {
            Image(systemName: model.state.systemImage)

            if model.badgeCount > 0 {
                Text("\(model.badgeCount)")
                    .font(.caption2.weight(.semibold))
                    .monospacedDigit()
            }
        }
        .accessibilityLabel(accessibilityLabel)
    }

    private var accessibilityLabel: String {
        guard model.badgeCount > 0 else {
            return "\(MenuBarCopy.appName): \(model.state.title)"
        }

        return "\(MenuBarCopy.appName): \(model.state.title), \(MenuBarCopy.badgeAccessibilityLabel(count: model.badgeCount))"
    }
}
