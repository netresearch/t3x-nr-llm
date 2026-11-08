// Offline tests of actual caller expressions with GitHub's implementation.
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {Parser,Lexer,Evaluator,data} from './expression-runtime/node_modules/@actions/expressions/dist/index.js';

const root=process.argv[2]??'/home/cybot/projects/t3x-nr-llm/merge-controller-wave3-20261031';
const source=process.argv[3]??'/home/cybot/projects/.github/observer-budget-wave3-20261031/.github/actions/dependency-merge-controller';
const prefix='.github/workflows/dependency-merge-measure-';
const read=file=>JSON.parse(execFileSync('yq',['-o=json','.',`${root}/${file}`],{encoding:'utf8'}));
const intent=read(`${prefix}intent.yml`),evaluate=read(`${prefix}evaluate.yml`);
const expected=JSON.parse(readFileSync(new URL('./policy-one-shot.json',import.meta.url)));
const sourceDigest=value=>execFileSync('python',['-c',
  'import sys,json;sys.path.insert(0,sys.argv[1]);import policy;value=json.loads(sys.argv[2]);policy.validate_policy(value);print(policy.digest(value))',
  source,JSON.stringify(value)],{encoding:'utf8'}).trim();
assert.equal(sourceDigest(expected),'db834633bedfa407eabe88a1f92a9ae5eb9af51996c04311b9b532b0356bd3b1');
const permission={contents:'write','pull-requests':'write',actions:'read',checks:'read',statuses:'read'};
function contract(workflow,phase) {
  assert.deepEqual(workflow.permissions,{});
  assert.equal(Object.keys(workflow.jobs).length,1);
  const job=Object.values(workflow.jobs)[0];
  assert.equal(job.uses,'netresearch/.github/.github/workflows/dependency-merge-controller.yml@main');
  assert.deepEqual(job.permissions,permission);
  assert.equal(job.with.phase,phase);
  assert.equal(job.with['observe-only'],true);
  assert.equal(job.with['caller-workflow'],`${prefix}${phase==='prepare'?'intent':'evaluate'}.yml`);
  assert.equal(job.with['intent-workflow'],`${prefix}intent.yml`);
  assert.deepEqual(JSON.parse(job.with.policy),expected);
  assert.equal('secrets' in job,false);
  assert.equal('steps' in job,false);
}
contract(intent,'prepare');contract(evaluate,'evaluate');
function triggerContract(workflow,phase) {
  const expected=phase==='prepare'
    ? {pull_request_target:{types:['synchronize'],branches:['main'],paths:['Build/decision/uv.lock','Build/reranker/uv.lock']}}
    : {workflow_run:{workflows:['CI'],types:['completed'],branches:['renovate/lock-file-maintenance']}};
  assert.deepEqual(workflow.on,expected);
}
triggerContract(intent,'prepare');triggerContract(evaluate,'evaluate');
const baseline=execFileSync('git',['-C',root,'show','c17b1ecba657749c15b60d3f71fb3b84081d9155:.github/workflows/auto-merge-deps.yml']);
assert.deepEqual(readFileSync(`${root}/.github/workflows/auto-merge-deps.yml`),baseline);
function convert(value) {
  if(value===null||value===undefined)return new data.Null();
  if(typeof value==='boolean')return new data.BooleanData(value);
  if(typeof value==='number')return new data.NumberData(value);
  if(typeof value==='string')return new data.StringData(value);
  if(Array.isArray(value))return new data.Array(...value.map(convert));
  return new data.Dictionary(...Object.entries(value).map(([key,value])=>({key,value:convert(value)})));
}
function scheduled(workflow,github,changedPaths=['Build/decision/uv.lock']) {
  const trigger=workflow.on[github.event_name];
  if(!trigger||!trigger.types.includes(github.event.action))return false;
  const branch=github.event_name==='workflow_run'?github.event.workflow_run.head_branch:github.event.pull_request.base.ref;
  if(!trigger.branches.includes(branch))return false;
  if(trigger.workflows&&!trigger.workflows.includes(github.event.workflow_run.name))return false;
  if(trigger.paths&&!trigger.paths.some(path=>changedPaths.includes(path)))return false;
  const expression=Object.values(workflow.jobs)[0].if;
  const parsed=new Parser(new Lexer(expression).lex().tokens,['github'],[]).parse();
  return new Evaluator(parsed,convert({github})).evaluate().coerceString()==='true';
}
function fixture(phase) {
  const repository='netresearch/t3x-nr-llm';
  return {repository,actor:'renovate[bot]',actor_id:'29139614',run_number:'1',run_attempt:'1',
    event_name:phase==='prepare'?'pull_request_target':'workflow_run',event:{
      action:phase==='prepare'?'synchronize':'completed',repository:{full_name:repository},sender:{type:'Bot',id:29139614,login:'renovate[bot]'},
      pull_request:{number:1197,user:{login:'renovate[bot]'},head:{ref:'renovate/lock-file-maintenance',repo:{full_name:repository}},base:{ref:'main',repo:{full_name:repository}}},
      workflow_run:{name:'CI',head_repository:{full_name:repository},head_branch:'renovate/lock-file-maintenance',event:'pull_request',run_attempt:1,conclusion:'success'}}};
}
let cases=0;
function check(workflow,name,github,wanted) {assert.equal(scheduled(workflow,github),wanted,name);cases++;}
for(const [workflow,phase] of [[intent,'prepare'],[evaluate,'evaluate']]) {
  check(workflow,`${phase} genuine-shape fixture`,fixture(phase),true);
  for(const key of ['run_number','run_attempt'])for(const value of ['0','2','01','1.0','1 ',null,'']) {
    const g=fixture(phase);g[key]=value;check(workflow,`${phase} rejects caller ${key} ${value}`,g,false);
  }
  const missing=fixture(phase);delete missing.run_number;check(workflow,`${phase} missing caller number`,missing,false);
  const foreign=fixture(phase);foreign.event.repository.full_name='foreign/repo';check(workflow,`${phase} foreign repository`,foreign,false);
}
for(const [name,mutate] of [
  ['other PR',g=>g.event.pull_request.number=1198],['other branch',g=>g.event.pull_request.head.ref='renovate/other'],
  ['other base',g=>g.event.pull_request.base.ref='develop'],['foreign head',g=>g.event.pull_request.head.repo.full_name='foreign/repo'],
  ['foreign base',g=>g.event.pull_request.base.repo.full_name='foreign/repo'],['human author',g=>g.event.pull_request.user.login='maintainer'],
  ['human actor',g=>{g.actor='maintainer';g.actor_id='1';g.event.sender={type:'User',id:1,login:'maintainer'};}],['Dependabot',g=>{g.actor='dependabot[bot]';g.actor_id='1';g.event.sender={type:'Bot',id:1,login:'dependabot[bot]'};g.event.pull_request.user.login='dependabot[bot]';}],
  ['opened',g=>g.event.action='opened'],['reopened',g=>g.event.action='reopened'],['review event',g=>g.event_name='pull_request_review']]) {
  const g=fixture('prepare');mutate(g);check(intent,`prepare rejects ${name}`,g,false);
}
for(const [name,mutate] of [
  ['other branch',g=>g.event.workflow_run.head_branch='renovate/other'],['ordinary branch',g=>g.event.workflow_run.head_branch='feature/test'],
  ['other producer',g=>g.event.workflow_run.name='Checks'],['legacy completion',g=>g.event.workflow_run.name='Auto-merge dependency PRs'],
  ['intent completion',g=>g.event.workflow_run.name='Dependency merge intent (measure once)'],['running',g=>g.event.action='in_progress'],
  ['failed',g=>g.event.workflow_run.conclusion='failure'],['cancelled',g=>g.event.workflow_run.conclusion='cancelled'],
  ['neutral',g=>g.event.workflow_run.conclusion='neutral'],['missing conclusion',g=>delete g.event.workflow_run.conclusion],
  ['rerun producer',g=>g.event.workflow_run.run_attempt=2],['missing producer attempt',g=>delete g.event.workflow_run.run_attempt],
  ['dispatch producer',g=>g.event.workflow_run.event='workflow_dispatch'],['MQ producer',g=>g.event.workflow_run.event='merge_group'],
  ['metadata event',g=>g.event_name='pull_request_target'],['review event',g=>g.event_name='pull_request_review']]) {
  const g=fixture('evaluate');mutate(g);check(evaluate,`evaluate rejects ${name}`,g,false);
}
const mutants=[
  ['observation disabled',j=>j.with['observe-only']=false],['dropped required entry',j=>{const p=JSON.parse(j.with.policy);p.required_checks.pop();j.with.policy=JSON.stringify(p);}],
  ['wrong ceiling',j=>{const p=JSON.parse(j.with.policy);p.observer_api_attempt_limit=63;j.with.policy=JSON.stringify(p);}],
  ['changed policy blob',j=>{const p=JSON.parse(j.with.policy);p.conditional_checks[0].workflow_blob_oid='0'.repeat(40);j.with.policy=JSON.stringify(p);}],
  ['wrong intent path',j=>j.with['intent-workflow']='.github/workflows/other.yml'],['wrong caller path',j=>j.with['caller-workflow']='.github/workflows/other.yml']
];
for(const [name,mutate] of mutants) {const copy=structuredClone(evaluate);mutate(Object.values(copy.jobs)[0]);assert.throws(()=>contract(copy,'evaluate'),assert.AssertionError,name);cases++;}
for(const [name,paths,wanted] of [
  ['first exact lockfile',['Build/decision/uv.lock'],true],
  ['second exact lockfile',['Build/reranker/uv.lock'],true],
  ['both exact lockfiles',['Build/decision/uv.lock','Build/reranker/uv.lock'],true],
  ['unrelated documentation',['Documentation/Adr/Adr214.rst'],false],
  ['wrong case',['build/decision/uv.lock'],false],
  ['lockfile-looking suffix',['Build/decision/uv.lock.backup'],false],
  ['empty file selection',[],false]]) {
  assert.equal(scheduled(intent,fixture('prepare'),paths),wanted,name);cases++;
}
for(const [name,mutate] of [
  ['drop path filter',w=>delete w.on.pull_request_target.paths],
  ['widen paths',w=>w.on.pull_request_target.paths.push('**')],
  ['change path case',w=>w.on.pull_request_target.paths[0]='build/decision/uv.lock'],
  ['replace exact path',w=>w.on.pull_request_target.paths[0]='.github/workflows/ci.yml']]) {
  const copy=structuredClone(intent);mutate(copy);assert.throws(()=>triggerContract(copy,'prepare'),assert.AssertionError,name);cases++;
}
for(const value of ['1','999999',null,'','029139614','29139614 ',' 29139614','29139614.0','renovate[bot]','２９１３９６１４']) {
  const g=fixture('prepare');g.event.sender.id=value;
  check(intent,`prepare rejects matching login with invalid provider sender ID ${JSON.stringify(value)}`,g,false);
}
const absentSenderId=fixture('prepare');delete absentSenderId.event.sender.id;
check(intent,'prepare rejects matching login without provider sender ID',absentSenderId,false);
const renamedActor=fixture('prepare');renamedActor.actor='renovate-previous-name';
check(intent,'prepare authorizes provider sender independently of mutable actor name',renamedActor,true);
const invalidSender=fixture('prepare');invalidSender.event.sender.id=1;
check(intent,'prepare rejects wrong provider sender despite matching actor ID and author',invalidSender,false);
for(const [name,mutate] of [
  ['User with canonical ID',g=>g.event.sender.type='User'],
  ['Organization with canonical ID',g=>g.event.sender.type='Organization'],
  ['unknown type with canonical ID',g=>g.event.sender.type='Ghost'],
  ['missing type with canonical ID',g=>delete g.event.sender.type],
  ['null type with canonical ID',g=>g.event.sender.type=null],
  ['empty type with canonical ID',g=>g.event.sender.type=''],
  ['numeric type with canonical ID',g=>g.event.sender.type=1],
  ['array type with canonical ID',g=>g.event.sender.type=['Bot']],
  ['object type with canonical ID',g=>g.event.sender.type={type:'Bot'}],
  ['ghost sender',g=>g.event.sender={type:'User',id:10137,login:'ghost'}],
  ['missing sender',g=>delete g.event.sender],
  ['null sender',g=>g.event.sender=null],
  ['string sender',g=>g.event.sender='renovate[bot]'],
  ['array sender',g=>g.event.sender=[]],
  ['empty sender',g=>g.event.sender={}],
  ['boolean ID',g=>g.event.sender.id=true],
  ['array ID',g=>g.event.sender.id=[29139614]],
  ['object ID',g=>g.event.sender.id={id:29139614}],
  ['fractional ID',g=>g.event.sender.id=29139614.5],
  ['wrong numeric ID',g=>g.event.sender.id=29139615],
  ['canonical numeric ID encoded as string',g=>g.event.sender.id='29139614']]) {
  const g=fixture('prepare');mutate(g);check(intent,`prepare rejects ${name}`,g,false);
}
const renamedSender=fixture('prepare');renamedSender.event.sender.login='renovate-previous-name';
check(intent,'prepare authorizes numeric typed sender independently of mutable sender name',renamedSender,true);
const absentActor=fixture('prepare');delete absentActor.actor;delete absentActor.actor_id;
check(intent,'prepare requires provider sender without an actor fallback',absentActor,true);
const otherActor=fixture('prepare');otherActor.actor='maintainer';otherActor.actor_id='1';
check(intent,'prepare binds original provider sender rather than unrelated actor fields',otherActor,true);
for(const [name,mutate] of [
  ['same-named fork producer',g=>g.event.workflow_run.head_repository.full_name='foreign/t3x-nr-llm'],
  ['different repository under same owner',g=>g.event.workflow_run.head_repository.full_name='netresearch/other'],
  ['missing producer head repository',g=>delete g.event.workflow_run.head_repository],
  ['null producer head repository',g=>g.event.workflow_run.head_repository=null],
  ['missing producer repository name',g=>delete g.event.workflow_run.head_repository.full_name],
  ['null producer repository name',g=>g.event.workflow_run.head_repository.full_name=null],
  ['empty producer repository name',g=>g.event.workflow_run.head_repository.full_name=''],
  ['string producer repository',g=>g.event.workflow_run.head_repository='netresearch/t3x-nr-llm']]) {
  const g=fixture('evaluate');mutate(g);check(evaluate,`evaluate rejects ${name}`,g,false);
}
console.log(JSON.stringify({cases,library:'@actions/expressions@0.3.61',policy_digest:sourceDigest(expected),
  controller_api_calls:0,native_events:0,scope:'Actual extracted expressions and exact trigger subset, offline fixtures; no hosted scheduler/origin/capacity/adoption proof.'},null,2));
