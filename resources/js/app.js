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

let confirmationDialog;
let resolveConfirmation;
let pendingConfirmation;
let confirmationTrigger;

const requestConfirmation = (message) => {
    if (pendingConfirmation) return pendingConfirmation;

    if (!confirmationDialog) {
        confirmationDialog = document.createElement("dialog");
        confirmationDialog.className = "modal confirmation-modal";
        confirmationDialog.setAttribute(
            "aria-labelledby",
            "confirmation-title",
        );
        confirmationDialog.innerHTML = `
            <div class="confirmation-mark" aria-hidden="true">?</div>
            <div class="confirmation-copy">
                <p class="panel-kicker">CONFIRM ACTION</p>
                <h2 id="confirmation-title">请确认操作</h2>
                <p data-confirmation-message></p>
            </div>
            <div class="modal-foot">
                <button class="button button-ghost" type="button" data-confirmation-cancel>取消</button>
                <button class="button button-primary" type="button" data-confirmation-accept>确认继续</button>
            </div>
        `;
        document.body.append(confirmationDialog);

        const finish = (confirmed) => {
            closeDialog(confirmationDialog);
            resolveConfirmation?.(confirmed);
            resolveConfirmation = undefined;
            pendingConfirmation = undefined;
            confirmationTrigger?.focus();
            confirmationTrigger = undefined;
        };
        confirmationDialog
            .querySelector("[data-confirmation-cancel]")
            .addEventListener("click", () => finish(false));
        confirmationDialog
            .querySelector("[data-confirmation-accept]")
            .addEventListener("click", () => finish(true));
        confirmationDialog.addEventListener("cancel", (event) => {
            event.preventDefault();
            finish(false);
        });
        confirmationDialog.addEventListener("click", (event) => {
            if (event.target === confirmationDialog) finish(false);
        });
    }

    confirmationDialog.querySelector(
        "[data-confirmation-message]",
    ).textContent = message;
    confirmationTrigger = document.activeElement;
    pendingConfirmation = new Promise((resolve) => {
        resolveConfirmation = resolve;
    });
    openDialog(confirmationDialog);
    confirmationDialog.querySelector("[data-confirmation-accept]").focus();

    return pendingConfirmation;
};

const dirtyForms = new Set();
const dirtyFormEntries = () =>
    [...dirtyForms].filter(
        (form) =>
            form.dataset.dirty === "true" && form.dataset.submitting !== "true",
    );

const confirmDirtyNavigation = async () => {
    const dirty = dirtyFormEntries();
    if (!dirty.length) return true;

    const message =
        dirty[0].dataset.dirtyMessage ||
        "This form has unsaved changes. Leave anyway?";
    if (!(await requestConfirmation(message))) return false;

    dirty.forEach((form) => {
        form.dataset.dirty = "false";
        dirtyForms.delete(form);
    });
    return true;
};

document.addEventListener("click", async (event) => {
    const navigation = event.target.closest(
        "a[href], [data-dialog-close], [data-dialog-open]",
    );
    if (navigation && dirtyFormEntries().length) {
        event.preventDefault();
        event.stopImmediatePropagation();
        if (await confirmDirtyNavigation()) navigation.click();
        return;
    }

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
        return;
    }

    const passwordToggle = event.target.closest("[data-password-toggle]");
    if (passwordToggle) {
        const input = passwordToggle
            .closest(".password-field")
            ?.querySelector("input");
        if (!input) return;
        input.type = input.type === "password" ? "text" : "password";
        passwordToggle.textContent =
            input.type === "password" ? "查看" : "隐藏";
        return;
    }

    const sensitiveToggle = event.target.closest("[data-sensitive-toggle]");
    if (sensitiveToggle) {
        const value = sensitiveToggle.parentElement?.querySelector(
            "[data-sensitive-value]",
        );
        if (!value) return;
        const visible = sensitiveToggle.dataset.visible === "true";
        if (visible) {
            value.textContent = value.dataset.masked;
            delete value.dataset.visible;
            sensitiveToggle.dataset.visible = "false";
            sensitiveToggle.textContent = "查看";
            return;
        }

        sensitiveToggle.disabled = true;
        try {
            const response = await window.axios.post(
                sensitiveToggle.dataset.revealUrl,
            );
            value.textContent = response.data.identifier;
            value.dataset.visible = response.data.identifier;
            sensitiveToggle.dataset.visible = "true";
            sensitiveToggle.textContent = "隐藏";
        } catch (error) {
            const message = error.response?.data?.message || "登录标识读取失败";
            window.alert(message);
        } finally {
            sensitiveToggle.disabled = false;
        }
    }
});

document.querySelectorAll("dialog[open]").forEach((dialog) => {
    if (typeof dialog.showModal === "function") {
        dialog.removeAttribute("open");
        dialog.showModal();
    }
});

document.querySelectorAll("dialog").forEach((dialog) => {
    dialog.addEventListener("click", async (event) => {
        if (event.target !== dialog) return;

        if (await confirmDirtyNavigation()) closeDialog(dialog);
    });
    dialog.addEventListener("cancel", async (event) => {
        if (!dirtyFormEntries().length) return;

        event.preventDefault();
        if (await confirmDirtyNavigation()) closeDialog(dialog);
    });
});

document.querySelectorAll("form[data-confirm]").forEach((form) => {
    form.addEventListener("submit", async (event) => {
        if (form.dataset.submitting === "true") {
            event.preventDefault();
            return;
        }
        if (form.dataset.confirmed === "true") {
            delete form.dataset.confirmed;
            form.dataset.submitting = "true";
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.disabled = true;
            });
            return;
        }

        event.preventDefault();
        if (!(await requestConfirmation(form.dataset.confirm))) return;

        form.dataset.confirmed = "true";
        if (event.submitter) {
            form.requestSubmit(event.submitter);
        } else {
            form.requestSubmit();
        }
    });
});

document.querySelectorAll("form[data-auto-submit]").forEach((form) => {
    form.requestSubmit();
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

document.querySelectorAll("[data-select-all]").forEach((toggle) => {
    const name = toggle.dataset.selectAll;
    const items = document.querySelectorAll(`[data-select-item="${name}"]`);
    toggle.addEventListener("change", () => {
        items.forEach((item) => {
            if (!item.disabled) item.checked = toggle.checked;
        });
    });
    items.forEach((item) => {
        item.addEventListener("change", () => {
            const enabled = [...items].filter(
                (candidate) => !candidate.disabled,
            );
            toggle.checked =
                enabled.length > 0 &&
                enabled.every((candidate) => candidate.checked);
            toggle.indeterminate =
                enabled.some((candidate) => candidate.checked) &&
                !toggle.checked;
        });
    });
});

document.querySelectorAll("form[data-dirty-guard]").forEach((form) => {
    const markDirty = () => {
        form.dataset.dirty = "true";
        dirtyForms.add(form);
    };

    form.addEventListener("input", markDirty);
    form.addEventListener("change", markDirty);
    form.addEventListener("submit", (event) => {
        queueMicrotask(() => {
            if (event.defaultPrevented) return;

            form.dataset.submitting = "true";
            form.dataset.dirty = "false";
            dirtyForms.delete(form);
        });
    });
});
