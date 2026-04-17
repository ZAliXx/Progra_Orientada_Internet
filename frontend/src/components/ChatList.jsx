import "../styles/chatlist.css";

export default function ChatList({ onOpenChat }) {
  return (
    <div className="sidebar">
      <button className="new-chat">
        <img src="/message_icon_black.png"/>
        <p>New Chat</p>
      </button>

      <div className="chat-list">
          <div className="chat-item" onClick={onOpenChat}>
            <div className="avatar">
              <img src="yeosang.jpg"></img>
            </div>
            <span>User name</span>
          </div>
      </div>
    </div>
  );
}
