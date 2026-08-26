/* Shared searchable, sortable, pageable table used by native UI v2 panels. */
(() => {
  "use strict";

  const collator = new Intl.Collator("en", { numeric: true, sensitivity: "base" });
  const text = value => String(value ?? "");
  const number = value => {
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : 0;
  };

  class P2KDataTable {
    constructor(options = {}) {
      this.root = options.root;
      this.columns = Array.isArray(options.columns) ? options.columns : [];
      this.rows = Array.isArray(options.rows) ? options.rows : [];
      this.searchInput = options.searchInput || null;
      this.filterInput = options.filterInput || null;
      this.countHost = options.countHost || null;
      this.pagerHost = options.pagerHost || null;
      this.emptyText = options.emptyText || "No rows match the current filters.";
      this.pageSize = Math.max(5, Number(options.pageSize) || 25);
      this.filterRow = typeof options.filterRow === "function" ? options.filterRow : () => true;
      this.searchText = typeof options.searchText === "function"
        ? options.searchText
        : row => this.columns.map(column => text(row[column.key])).join(" ");
      this.rowClass = typeof options.rowClass === "function" ? options.rowClass : () => "";
      this.onStateChange = typeof options.onStateChange === "function" ? options.onStateChange : () => {};
      this.remoteLoader = typeof options.remoteLoader === "function" ? options.remoteLoader : null;
      this.onRemoteState = typeof options.onRemoteState === "function" ? options.onRemoteState : () => {};
      this.totalRows = Math.max(0, Number(options.totalRows) || this.rows.length);
      this.remoteTimer = 0;
      this.remoteRun = 0;
      this.state = {
        query: text(options.state?.query),
        filter: text(options.state?.filter || "all"),
        sort: text(options.state?.sort || this.columns[0]?.key || ""),
        direction: options.state?.direction === "asc" ? "asc" : "desc",
        page: Math.max(1, Number(options.state?.page) || 1)
      };
      this.bound = false;
      this.bind();
      this.render();
    }

    bind() {
      if (this.bound) return;
      this.bound = true;
      if (this.searchInput) {
        this.searchInput.value = this.state.query;
        this.searchInput.addEventListener("input", () => {
          this.state.query = this.searchInput.value;
          this.state.page = 1;
          this.changed({ debounce: true });
        });
      }
      if (this.filterInput) {
        this.filterInput.value = this.state.filter;
        this.filterInput.addEventListener("change", () => {
          this.state.filter = this.filterInput.value;
          this.state.page = 1;
          this.changed();
        });
      }
      this.root?.querySelectorAll("th[data-key]").forEach(header => {
        header.tabIndex = 0;
        header.setAttribute("role", "button");
        const activate = () => {
          const key = header.dataset.key || "";
          if (!key) return;
          if (this.state.sort === key) this.state.direction = this.state.direction === "asc" ? "desc" : "asc";
          else {
            this.state.sort = key;
            const column = this.columns.find(item => item.key === key);
            this.state.direction = column?.defaultDirection || (column?.type === "text" ? "asc" : "desc");
          }
          this.state.page = 1;
          this.changed();
        };
        header.addEventListener("click", activate);
        header.addEventListener("keydown", event => {
          if (event.key !== "Enter" && event.key !== " ") return;
          event.preventDefault();
          activate();
        });
      });
    }

    setRows(rows) {
      this.rows = Array.isArray(rows) ? rows : [];
      this.totalRows = this.rows.length;
      this.state.page = Math.min(this.state.page, Math.max(1, Math.ceil(this.filteredRows().length / this.pageSize)));
      this.render();
    }

    setRemoteData(rows, totalRows) {
      this.rows = Array.isArray(rows) ? rows : [];
      this.totalRows = Math.max(0, Number(totalRows) || 0);
      const pages = Math.max(1, Math.ceil(this.totalRows / this.pageSize));
      this.state.page = Math.min(this.state.page, pages);
      this.render();
    }

    setState(next = {}, { notify = false } = {}) {
      this.state = {
        ...this.state,
        ...next,
        query: text(next.query ?? this.state.query),
        filter: text(next.filter ?? this.state.filter),
        direction: next.direction === "asc" ? "asc" : next.direction === "desc" ? "desc" : this.state.direction,
        page: Math.max(1, Number(next.page ?? this.state.page) || 1)
      };
      if (this.searchInput) this.searchInput.value = this.state.query;
      if (this.filterInput) this.filterInput.value = this.state.filter;
      if (this.remoteLoader) this.loadRemote();
      else this.render();
      if (notify) this.onStateChange({ ...this.state });
    }

    changed({ debounce = false } = {}) {
      this.onStateChange({ ...this.state });
      if (!this.remoteLoader) {
        this.render();
        return;
      }
      window.clearTimeout(this.remoteTimer);
      if (debounce) this.remoteTimer = window.setTimeout(() => this.loadRemote(), 280);
      else this.loadRemote();
    }

    async loadRemote() {
      if (!this.remoteLoader) return;
      const run = ++this.remoteRun;
      this.onRemoteState({ loading: true, state: { ...this.state } });
      try {
        const payload = await this.remoteLoader({ ...this.state }, this.pageSize);
        if (run !== this.remoteRun) return;
        this.setRemoteData(payload?.rows || [], payload?.totalRows ?? payload?.pagination?.total_rows ?? 0);
        this.onRemoteState({ loading: false, state: { ...this.state }, payload });
      } catch (error) {
        if (run !== this.remoteRun) return;
        this.onRemoteState({ loading: false, state: { ...this.state }, error });
      }
    }

    filteredRows() {
      const needle = this.state.query.trim().toLowerCase();
      const filtered = this.rows.filter(row => {
        if (!this.filterRow(row, this.state.filter)) return false;
        return !needle || this.searchText(row).toLowerCase().includes(needle);
      });
      const column = this.columns.find(item => item.key === this.state.sort) || this.columns[0];
      if (!column) return filtered;
      const direction = this.state.direction === "asc" ? 1 : -1;
      return [...filtered].sort((left, right) => {
        const a = typeof column.value === "function" ? column.value(left) : left[column.key];
        const b = typeof column.value === "function" ? column.value(right) : right[column.key];
        const result = column.type === "number" ? number(a) - number(b) : collator.compare(text(a), text(b));
        return result * direction;
      });
    }

    render() {
      if (!this.root) return;
      const tbody = this.root.querySelector("tbody");
      if (!tbody) return;
      this.root.querySelectorAll("th[data-key]").forEach(header => {
        const active = header.dataset.key === this.state.sort;
        header.dataset.direction = active ? this.state.direction : "";
        header.setAttribute("aria-sort", active ? (this.state.direction === "asc" ? "ascending" : "descending") : "none");
      });
      const rows = this.remoteLoader ? this.rows : this.filteredRows();
      const totalRows = this.remoteLoader ? this.totalRows : rows.length;
      const pages = Math.max(1, Math.ceil(totalRows / this.pageSize));
      this.state.page = Math.min(this.state.page, pages);
      const start = (this.state.page - 1) * this.pageSize;
      const pageRows = this.remoteLoader ? rows : rows.slice(start, start + this.pageSize);
      tbody.replaceChildren();
      if (!pageRows.length) {
        const tr = document.createElement("tr");
        const td = document.createElement("td");
        td.colSpan = Math.max(1, this.columns.length);
        td.className = "p2k-table-empty";
        td.textContent = this.emptyText;
        tr.appendChild(td);
        tbody.appendChild(tr);
      } else {
        pageRows.forEach(row => {
          const tr = document.createElement("tr");
          tr.className = this.rowClass(row) || "";
          this.columns.forEach(column => {
            const td = document.createElement("td");
            if (column.align) td.classList.add(`is-${column.align}`);
            if (typeof column.render === "function") {
              const rendered = column.render(row, td);
              if (rendered instanceof Node) td.appendChild(rendered);
              else if (rendered !== undefined && rendered !== null) td.textContent = text(rendered);
            } else td.textContent = text(row[column.key]);
            tr.appendChild(td);
          });
          tbody.appendChild(tr);
        });
      }
      if (this.countHost) {
        const first = totalRows ? start + 1 : 0;
        const last = Math.min(totalRows, start + pageRows.length);
        this.countHost.textContent = totalRows ? `${first}–${last} of ${totalRows}` : "0 rows";
      }
      this.renderPager(pages);
    }

    renderPager(pages) {
      if (!this.pagerHost) return;
      this.pagerHost.replaceChildren();
      const button = (label, page, disabled = false, current = false) => {
        const node = document.createElement("button");
        node.type = "button";
        node.className = "p2k-table-page";
        node.textContent = label;
        node.disabled = disabled;
        if (current) node.setAttribute("aria-current", "page");
        node.addEventListener("click", () => {
          this.state.page = page;
          this.changed();
        });
        return node;
      };
      this.pagerHost.append(button("‹", Math.max(1, this.state.page - 1), this.state.page <= 1));
      const windowStart = Math.max(1, Math.min(this.state.page - 2, pages - 4));
      const windowEnd = Math.min(pages, windowStart + 4);
      for (let page = windowStart; page <= windowEnd; page += 1) {
        this.pagerHost.append(button(String(page), page, false, page === this.state.page));
      }
      this.pagerHost.append(button("›", Math.min(pages, this.state.page + 1), this.state.page >= pages));
    }
  }

  window.P2KDataTable = P2KDataTable;
})();
