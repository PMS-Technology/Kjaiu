import "./bootstrap";

const openDialog = (dialog) => {
    if (!dialog) return;

    if (typeof dialog.showModal === "function" && !dialog.open) {
        dialog.showModal();
    } else {
        dialog.setAttribute("open", "");
    }
};

const closeDialog = (dialog) => {
    if (!dialog) return;

    if (typeof dialog.close === "function") {
        dialog.close();
    } else {
        dialog.removeAttribute("open");
    }
};

document.addEventListener("click", (event) => {
    const opener = event.target.closest("[data-dialog-open]");
    if (opener) {
        openDialog(document.getElementById(opener.dataset.dialogOpen));
        return;
    }

    const closer = event.target.closest("[data-dialog-close]");
    if (closer) {
        closeDialog(closer.closest("dialog"));
        return;
    }

    const dismiss = event.target.closest("[data-dismiss-parent]");
    if (dismiss) {
        dismiss.parentElement?.remove();
        return;
    }

    const removePrice = event.target.closest("[data-remove-price]");
    if (removePrice) {
        removePrice.closest("[data-price-row]")?.remove();
        return;
    }

    const addPrice = event.target.closest("[data-add-price]");
    if (addPrice) {
        const builder = addPrice.closest("[data-price-builder]");
        const template = builder?.querySelector("[data-price-template]");
        if (!builder || !template) return;

        const index = Number(builder.dataset.nextIndex || 0);
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll("[name]").forEach((element) => {
            element.name = element.name.replace("__INDEX__", String(index));
        });
        builder.insertBefore(fragment, template);
        builder.dataset.nextIndex = String(index + 1);
    }
});

document.querySelectorAll("dialog[open]").forEach((dialog) => {
    if (typeof dialog.showModal === "function") {
        dialog.removeAttribute("open");
        dialog.showModal();
    }
});

document.querySelectorAll("dialog").forEach((dialog) => {
    dialog.addEventListener("click", (event) => {
        if (event.target === dialog) closeDialog(dialog);
    });
});

document.querySelectorAll("form[data-confirm]").forEach((form) => {
    form.addEventListener("submit", (event) => {
        if (form.dataset.submitting === "true") {
            event.preventDefault();
            return;
        }
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
            return;
        }

        form.dataset.submitting = "true";
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.disabled = true;
        });
    });
});

document.querySelectorAll("[data-stock-toggle]").forEach((toggle) => {
    const input = toggle.closest("form")?.querySelector("[data-stock-input]");
    const sync = () => {
        if (!input) return;
        input.required = toggle.checked;
        input.disabled = !toggle.checked;
    };

    toggle.addEventListener("change", sync);
    sync();
});
