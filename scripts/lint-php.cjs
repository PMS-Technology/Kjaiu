const fs = require("node:fs");
const path = require("node:path");
const Parser = require("php-parser");

const parser = new Parser({
    parser: { php7: true, suppressErrors: false },
    ast: { withPositions: true },
});
const roots = [
    "app",
    "bootstrap",
    "config",
    "database",
    "lang",
    "routes",
    "tests",
];
const files = [];

const walk = (directory) => {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        const file = path.join(directory, entry.name);
        if (entry.isDirectory()) walk(file);
        else if (file.endsWith(".php")) files.push(file);
    }
};

for (const root of roots) {
    if (fs.existsSync(root)) walk(root);
}

let errors = 0;
for (const file of files) {
    try {
        parser.parseCode(fs.readFileSync(file, "utf8"), file);
    } catch (error) {
        errors += 1;
        console.error(`${file}: ${error.message}`);
    }
}

console.log(`Parsed ${files.length} PHP files; errors: ${errors}`);
if (errors > 0) process.exit(1);
