'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Execute the shipped table, action-cell and traffic-dialog functions. The hook
// fixture models React's dependency comparison and descendant state loss when
// a component type changes. This is a deterministic regression check, not a DOM
// or browser acceptance test.
const argument = process.argv.indexOf('--bundle');
const bundlePath = argument < 0
  ? path.resolve(__dirname, '../../public/assets/admin/assets/index-CEIYH7i8.js')
  : path.resolve(process.argv[argument + 1] || '');
const bundle = fs.readFileSync(bundlePath, 'utf8');

function section(start, end) {
  const first = bundle.indexOf(start);
  const last = bundle.indexOf(end, first + start.length);
  assert.ok(first >= 0 && last > first, `Audited bundle section not found: ${start}`);
  assert.equal(bundle.indexOf(start, first + 1), -1, `Ambiguous section: ${start}`);
  return bundle.slice(first, last);
}

const tableCode = section('function Z8t(', 'const Y8t=');
const dialogCode = section('function E8t(', 'function L8t(');
const renderCode = section('function kGt(', 'function NGt(');
const columnsCode = section('H8t=(e,t,n,i,r)=>[', '],z8t=H.createContext') + ']';
let activeInstance = null;
let activeHook = 0;
let parentOptions = null;
let language = 'zh';
let translate = key => `${language}:${key}`;
const provider = {setIsOpen() {}, setEditingUser() {}};
const queryCalls = [];

function node(type, props = {}, key) { return {type, props, key}; }
function run(instance, component, props) {
  const previous = activeInstance;
  const previousHook = activeHook;
  activeInstance = instance;
  activeHook = 0;
  try { return component(props); }
  finally { activeInstance = previous; activeHook = previousHook; }
}
function instance() { return {hooks: []}; }
function sameDependencies(first, second) {
  return first && second && first.length === second.length &&
    first.every((value, index) => Object.is(value, second[index]));
}
const hooks = {
  createElement: node,
  useState(initial) {
    assert.ok(activeInstance, 'State requires a mounted component');
    const owner = activeInstance;
    const index = activeHook++;
    if (!(index in owner.hooks)) {
      const state = {value: typeof initial === 'function' ? initial() : initial};
      state.set = value => {
        state.value = typeof value === 'function' ? value(state.value) : value;
      };
      owner.hooks[index] = state;
    }
    const state = owner.hooks[index];
    return [state.value, state.set];
  },
  useMemo(factory, dependencies) {
    assert.ok(activeInstance, 'Memo requires a mounted component');
    const index = activeHook++;
    const previous = activeInstance.hooks[index];
    if (!previous || !sameDependencies(previous.dependencies, dependencies)) {
      activeInstance.hooks[index] = {value: factory(), dependencies: [...dependencies]};
    }
    return activeInstance.hooks[index].value;
  }
};

const context = vm.createContext({
  H: hooks,
  Q: {jsx: node, jsxs: node},
  jy() { return {t: translate}; },
  $8t() { return provider; },
  Im(...values) { return values.filter(Boolean).join(' '); },
  N8t: [],
  pC(options) {
    queryCalls.push(options);
    return {data: {data: [], total: 0}, isLoading: false};
  },
  NGt(options) {
    if (options.columns !== context.N8t) {
      parentOptions = options;
      return options;
    }
    return {
      getHeaderGroups: () => [],
      getRowModel: () => ({rows: []}),
      getState: () => options.state,
      getPageCount: () => options.pageCount,
      getCanPreviousPage: () => options.state.pagination.pageIndex > 0,
      getCanNextPage: () => false,
      setPageSize(pageSize) {
        options.onPaginationChange(previous => ({...previous, pageSize}));
      },
      previousPage() {
        options.onPaginationChange(previous => ({...previous, pageIndex: previous.pageIndex - 1}));
      },
      nextPage() {
        options.onPaginationChange(previous => ({...previous, pageIndex: previous.pageIndex + 1}));
      }
    };
  }
});
// JSX components are opaque nodes: only the actual action cell and traffic
// dialog are executed. This keeps unrelated application widgets out of scope.
for (const code of [tableCode, dialogCode, columnsCode]) {
  for (const match of code.matchAll(/Q\.jsxs?\(([\w$]+),/g)) {
    if (!(match[1] in context)) context[match[1]] = match[1];
  }
}
for (const name of ['vGt', 'wGt', 'CGt', 'SGt', 'yGt', 'xGt']) context[name] = () => ({});
vm.runInContext(columnsCode + ';' + renderCode + dialogCode + tableCode, context,
  {filename: path.basename(bundlePath) + ':extracted-user-table'});

function find(tree, predicate) {
  if (!tree || typeof tree !== 'object') return undefined;
  if (predicate(tree)) return tree;
  const children = tree.props?.children;
  for (const child of Array.isArray(children) ? children : [children]) {
    const found = find(child, predicate);
    if (found) return found;
  }
}
function findType(tree, type) {
  const found = find(tree, candidate => candidate.type === type);
  assert.ok(found, `Missing rendered node: ${typeof type === 'function' ? type.name : type}`);
  return found;
}

const noOp = () => {};
let refreshCount = 0;
const props = {
  data: [{id: 7, email: 'traffic-fixture@example.invalid', total_used: 100}],
  rowCount: 1,
  sorting: [], setSorting: noOp,
  columnVisibility: {}, setColumnVisibility: noOp,
  rowSelection: {}, setRowSelection: noOp,
  columnFilters: [], setColumnFilters: noOp,
  pagination: {pageIndex: 0, pageSize: 20}, setPagination: noOp,
  refetch() { refreshCount++; },
  serverGroupList: [], subscriptionPlans: [], isLoading: false
};
const parent = instance();
let actionType = null;
let trafficInstance = null;
let trafficElement = null;
let menu = null;
let dialog = null;
let mounts = 0;

function renderTraffic() {
  dialog = run(trafficInstance, trafficElement.type, trafficElement.props);
  return dialog;
}
function renderParent() {
  run(parent, context.Z8t, props);
  const cell = parentOptions.columns.find(column => column.id === 'actions').cell;
  const element = context.kGt(cell, {
    row: {original: props.data[0]},
    table: {setColumnFilters: noOp}
  });
  assert.equal(element.type, cell, 'Use the shipped flexRender component-type behavior');
  if (element.type !== actionType) {
    // React replaces this component and all descendant hooks on a type change.
    actionType = element.type;
    trafficInstance = instance();
    mounts++;
  }
  menu = element.type(element.props);
  trafficElement = findType(menu, context.E8t);
  return renderTraffic();
}

let passed = 0;
function test(name, callback) {
  try { callback(); passed++; console.log(`PASS ${name}`); }
  catch (error) { console.error(`FAIL ${name}`); throw error; }
}

test('traffic dialog opens and changes its pagination through shipped handlers', () => {
  renderParent();
  assert.equal(dialog.props.open, false);
  dialog.props.onOpenChange(true);
  renderTraffic();
  assert.equal(dialog.props.open, true);
  findType(dialog, context.yzt).props.onValueChange('40');
  renderTraffic();
  assert.equal(queryCalls.at(-1).queryKey[2].pageSize, 40);
});

test('changed user-list results preserve the open dialog, pagination and current row', () => {
  const originalType = actionType;
  const originalMounts = mounts;
  for (let index = 1; index <= 6; index++) {
    props.data = [{...props.data[0], total_used: 100 + index, email: `updated-${index}@example.invalid`}];
    renderParent();
    assert.equal(dialog.props.open, true, 'Polling must not reset the open state');
    assert.equal(actionType, originalType, 'Polling must retain the action component type');
    assert.equal(queryCalls.at(-1).queryKey[2].pageSize, 40, 'Polling must retain dialog pagination');
    assert.equal(findType(menu, context.L8t).props.user, props.data[0],
      'Stable cell functions must still receive the latest user data');
  }
  assert.equal(mounts, originalMounts, 'Polling must not remount the action cell');
});

test('manual close and reopen still work after list refreshes', () => {
  dialog.props.onOpenChange(false);
  renderTraffic();
  assert.equal(dialog.props.open, false);
  renderParent();
  assert.equal(dialog.props.open, false);
  dialog.props.onOpenChange(true);
  renderTraffic();
  assert.equal(dialog.props.open, true);
});

test('locale changes refresh labels and refresh callback changes stay current', () => {
  const oldType = actionType;
  language = 'en';
  translate = key => `${language}:${key}`;
  renderParent();
  assert.notEqual(actionType, oldType, 'Changed translation function must invalidate column memoization');
  assert.ok(find(menu, candidate => candidate.props?.['aria-label'] === 'en:columns.actions'));
  let replacementRefresh = 0;
  props.refetch = () => { replacementRefresh++; };
  renderParent();
  findType(menu, context.L8t).props.onSuccess();
  assert.equal(replacementRefresh, 1, 'Action must call the newest refresh callback');
  assert.equal(refreshCount, 0, 'Action must not retain the old refresh callback');
});

test('replaced edit-provider callbacks stay current', () => {
  const calls = [];
  provider.setEditingUser = user => calls.push(['user', user]);
  renderParent();
  const firstEdit = find(menu, candidate => candidate.type === context.$st &&
    candidate.props?.onSelect?.toString().includes('n(t.original),i(!0)'));
  assert.ok(firstEdit, 'Find the actual edit action');
  firstEdit.props.onSelect({preventDefault() {}});
  assert.equal(calls[0][1], props.data[0]);
  provider.setIsOpen = value => calls.push(['open', value]);
  renderParent();
  const secondEdit = find(menu, candidate => candidate.type === context.$st &&
    candidate.props?.onSelect?.toString().includes('n(t.original),i(!0)'));
  secondEdit.props.onSelect({preventDefault() {}});
  assert.equal(calls.at(-1)[0], 'open');
  assert.equal(calls.at(-1)[1], true);
});

console.log(`${passed} regression checks passed (hook/component-identity fixture; no browser).`);
